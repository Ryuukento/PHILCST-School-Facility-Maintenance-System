<?php

namespace App\Services;

use App\Models\SchoolSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AnalyticsService
{
    public function inventorySummary(array $filters = []): array
    {
        // Cache key is versioned: v2 added the bodega/room placement split below.
        // v3 (TASK 78) — "low stock" now reads the canonical i.status column
        // (derived from reorder_level by InventoryStatusService, the same
        // source the Inventory page and Dashboard already use) instead of the
        // undocumented low_stock_threshold_override/category-default
        // COALESCE, which disagreed with Item.status for ~24 of 50 items.
        // "threshold" in the payload is now i.reorder_level (same key, so the
        // Inventory Reports frontend needs no changes), and low_stock_items
        // only contains items whose status is exactly 'low_stock' — mutually
        // exclusive from out_of_stock, matching the Inventory page's badges.
        // Bumping the version stops pre-v3 cached payloads (old semantics)
        // from being served for the remainder of their TTL.
        //
        // v4 (TASK 6B PHASE 2) — the placement split is now keyed on
        // items.item_type instead of the inventory_room_id / room_id FKs, and
        // the payload keys were renamed bodega_* -> inventory_*. See the
        // comment on the split itself below for why that is value-preserving.
        // v5 (INVENTORY REPORTS PRIORITY 5) — added the optional date_to ("as
        // of date") filter below. The system keeps no historical stock
        // ledger, so this can only ever limit WHICH items are counted (those
        // added on or before the given date, via items.created_at) — every
        // quantity in the response still reflects CURRENT stock on hand, not
        // a historical balance. Bumping the version is precautionary, not
        // required for correctness: the cache key already hashes $filters,
        // so an unfiltered call and a date_to call were never going to
        // collide either way.
        //
        // v6 (INVENTORY REPORTS PER ROOM FIX) — room_items/room_quantity/
        // room_count_with_items were computed only from items.item_type =
        // 'room_asset' rows, same blind spot documented on
        // App\Support\DeployedItemsQuery: items placed via the Dispatch ->
        // Approve -> Release workflow never get items.room_id/item_type
        // updated (one item can be split across many dispatches/rooms, so
        // there's no single room to stamp on the item row), so a room
        // stocked entirely through dispatches counted as "0 items in rooms"
        // here even though the Per Room report and Buildings Overview both
        // showed it correctly. This bucket now unions in released
        // dispatch_items, same as DeployedItemsQuery::perRoom(). Bumping the
        // version so old cached payloads (undercounting rooms) aren't served
        // for the rest of their 120s TTL. total_quantity/inventory_* are
        // untouched — total_quantity must keep meaning "current central
        // stock on hand" since the Semestral report's Current Stock Summary
        // card reuses this same payload.
        $cacheKey = 'analytics.inventorySummary:v6:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $query = DB::table('items as i')
                ->selectRaw('i.id, i.name, i.quantity, i.room_id, i.item_type, i.reorder_level as threshold, i.status');

            if (!empty($filters['category_id'])) {
                $query->where('i.category_id', (int)$filters['category_id']);
            }

            if (!empty($filters['department_id'])) {
                $query->where('i.department_id', (int)$filters['department_id']);
            }

            if (!empty($filters['room_id'])) {
                $query->where('i.room_id', (int)$filters['room_id']);
            }

            // "As of date" -- restricts to items that already existed (were
            // added to the system) on or before this date. See the v5 note
            // above: this is a WHICH-ITEMS filter, not a historical quantity
            // reconstruction.
            if (!empty($filters['date_to'])) {
                $query->whereDate('i.created_at', '<=', $filters['date_to']);
            }

            $items = $query->get();

            $totalQuantity = (int) $items->sum('quantity');
            $distinctItems = (int) $items->count();

            $lowStock = $items->filter(function ($it) {
                return $it->status === 'low_stock';
            })->values();

            // Placement split — derived from the single $items collection already
            // fetched above, so this adds ZERO extra queries.
            //
            // TASK 6B PHASE 2 — the discriminator is now items.item_type:
            // 'inventory_stock' = still held centrally in Inventory,
            // 'room_asset'      = already deployed into a room / laboratory.
            //
            // This is value-preserving, not a redefinition. The previous split
            // used the FKs (inventory_room_id set = "in a bodega", room_id set
            // = "in a room"), and the Phase 1 audit verified the invariant
            // `inventory_room_id IS NOT NULL <=> item_type = 'inventory_stock'`
            // and `room_id IS NOT NULL <=> item_type = 'room_asset'` holds for
            // every row — so both keys produce identical numbers. Keying on
            // item_type is what lets the inventory-room concept disappear from
            // the UI without any metric moving.
            //
            // (The stale note that used to sit here — claiming every row is
            // item_type='inventory_stock' so the column is a constant — was
            // written before room assets existed and is no longer true.)
            //
            // The two buckets are counted independently so neither number is
            // ever inflated by the other; anything with an unrecognised
            // item_type is reported separately as unplaced rather than being
            // silently folded into one of them.
            $inInventory  = $items->filter(fn ($it) => $it->item_type === 'inventory_stock');
            $inRooms      = $items->filter(fn ($it) => $it->item_type === 'room_asset');
            $unplaced     = $items->filter(fn ($it) => ! in_array($it->item_type, ['inventory_stock', 'room_asset'], true));

            // v6 — room_items/room_quantity/room_count_with_items now come from
            // the same "deployed to a room" union as DeployedItemsQuery::perRoom()
            // (released dispatch_items + direct room_asset rows), not just the
            // room_asset half. Rebuilt here (rather than calling the shared
            // helper directly) so category_id/room_id filters above still apply
            // to this bucket too, by joining dispatch_items back to items.
            $dispatchDeployed = DB::table('dispatches as d')
                ->join('dispatch_items as di', 'd.id', '=', 'di.dispatch_id')
                ->join('items as di_item', 'di.item_id', '=', 'di_item.id')
                ->where('d.status', 'released')
                ->whereNotNull('d.room_id')
                ->select(['d.room_id as room_id', 'di.quantity as quantity']);

            if (!empty($filters['category_id'])) {
                $dispatchDeployed->where('di_item.category_id', (int) $filters['category_id']);
            }
            if (!empty($filters['room_id'])) {
                $dispatchDeployed->where('d.room_id', (int) $filters['room_id']);
            }
            // No date_to/department_id filter here: date_to is a "which items
            // exist yet" filter (items.created_at) that doesn't map cleanly
            // onto a dispatch batch, and department_id already has no column
            // to filter on items (pre-existing, unrelated to this fix).

            $directDeployed = DB::table('items')
                ->where('item_type', 'room_asset')
                ->whereNotNull('room_id')
                ->select(['room_id', 'quantity']);

            if (!empty($filters['category_id'])) {
                $directDeployed->where('category_id', (int) $filters['category_id']);
            }
            if (!empty($filters['room_id'])) {
                $directDeployed->where('room_id', (int) $filters['room_id']);
            }

            $deployed = $dispatchDeployed->unionAll($directDeployed)->get();

            return [
                'total_quantity' => $totalQuantity,
                'distinct_items' => $distinctItems,
                'low_stock_count' => $lowStock->count(),
                'low_stock_items' => $lowStock,

                // v4 placement split (v2/v3 called these bodega_*)
                'inventory_items'     => $inInventory->count(),
                'inventory_quantity'  => (int) $inInventory->sum('quantity'),
                // v6: counts/sums every deployed batch (dispatch or direct),
                // not distinct items — matches how Buildings Overview and the
                // Per Room report already count "items in this room".
                'room_items'          => $deployed->count(),
                'room_quantity'       => (int) $deployed->sum('quantity'),
                'unplaced_items'      => $unplaced->count(),
                'unplaced_quantity'   => (int) $unplaced->sum('quantity'),
                'room_count_with_items' => $deployed->pluck('room_id')->unique()->count(),
            ];
        });
    }

    public function lowStock(array $filters = []): array
    {
        // TASK 78 — standardized on the canonical i.status column (see
        // inventorySummary() above for the full rationale). "Low stock" here
        // means status === 'low_stock' specifically, not out_of_stock.
        $cacheKey = 'analytics.lowStock:v2:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $q = DB::table('items as i')
                ->selectRaw('i.id, i.name, i.quantity, i.reorder_level as threshold, i.status')
                ->where('i.status', 'low_stock');

            if (!empty($filters['category_id'])) {
                $q->where('i.category_id', (int)$filters['category_id']);
            }
            if (!empty($filters['department_id'])) {
                $q->where('i.department_id', (int)$filters['department_id']);
            }
            if (!empty($filters['room_id'])) {
                $q->where('i.room_id', (int)$filters['room_id']);
            }

            $items = $q->orderBy('i.quantity')->limit(1000)->get();
            return ['items' => $items];
        });
    }

    public function damagedItems(array $filters = []): array
    {
        $cacheKey = 'analytics.damagedItems:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $q = DB::table('damage_reports as d')
                ->join('items as i', 'i.id', '=', 'd.item_id')
                ->selectRaw('i.id as item_id, i.name, COUNT(d.id) as damage_count')
                ->groupBy('i.id', 'i.name')
                ->orderByDesc('damage_count')
                ->limit(100);

            if (!empty($filters['date_from'])) {
                $q->whereDate('d.created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $q->whereDate('d.created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['department_id'])) {
                $q->where('d.department_id', (int)$filters['department_id']);
            }

            if (!empty($filters['room_id'])) {
                $q->where('d.room_id', (int)$filters['room_id']);
            }

            $results = $q->get();
            return ['most_damaged' => $results];
        });
    }

    public function dispatchReport(array $filters = []): array
    {
        $cacheKey = 'analytics.dispatchReport:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $q = DB::table('dispatch_items as di')
                ->join('dispatches as d', 'd.id', '=', 'di.dispatch_id')
                ->join('items as i', 'i.id', '=', 'di.item_id')
                ->selectRaw('d.id as dispatch_id, d.dispatch_code, d.department_id, COUNT(di.id) as items_count, SUM(di.quantity) as total_quantity')
                ->groupBy('d.id', 'd.dispatch_code', 'd.department_id')
                ->orderByDesc('d.created_at')
                ->limit(200);

            if (!empty($filters['date_from'])) {
                $q->whereDate('d.created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $q->whereDate('d.created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['department_id'])) {
                $q->where('d.department_id', (int)$filters['department_id']);
            }

            return ['dispatches' => $q->get()];
        });
    }

    // TASK 13 PHASE 8 (Repair retirement) — repairReport() was removed here.
    // repair_requests was its BASE table, so it is Repair-only: there is no
    // non-Repair subset of it left to preserve.
    //
    // Note the two neighbours it sat between, both of which are KEPT:
    // dispatchReport() above is Dispatch data, and replacementReport() below
    // reads damage_reports.replacement_transaction_id — Damage Report
    // replacement data, which this retirement explicitly preserves. Neither
    // has anything to do with the Repair Request module despite the
    // vocabulary overlap.

    public function replacementReport(array $filters = []): array
    {
        $cacheKey = 'analytics.replacementReport:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $q = DB::table('damage_reports as d')
                ->selectRaw('d.id as damage_id, d.replacement_transaction_id, d.status, d.replacement_item_id')
                ->whereNotNull('d.replacement_transaction_id')
                ->orderByDesc('d.created_at')
                ->limit(200);

            if (!empty($filters['date_from'])) {
                $q->whereDate('d.created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $q->whereDate('d.created_at', '<=', $filters['date_to']);
            }
            if (!empty($filters['department_id'])) {
                $q->where('d.department_id', (int)$filters['department_id']);
            }

            return ['replacements' => $q->get()];
        });
    }

    public function overview(array $filters = []): array
    {
        // TASK 13 PHASE 8 — v2: the payload no longer carries 'repair_trends'
        // (see below). Bumped so a pre-v2 cached payload that still contains
        // the retired series is not served for the remainder of its TTL.
        // Follows the same convention monthlyInventoryComparison() already
        // uses, and avoids having to flush the cache.
        $cacheKey = 'analytics.overview:v2:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            // last 12 months damaged trend
            $damaged = DB::select(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(id) as cnt FROM damage_reports WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym"
            );

            // TASK 13 PHASE 8 (Repair retirement) — the repair_requests
            // 12-month trend query was removed here, and 'repair_trends' with
            // it (see the return below). overview() is a SHARED method, so
            // only the Repair series was dropped: damaged, dispatch and stock
            // movement trends are untouched.
            $dispatches = DB::select(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(id) as cnt FROM dispatches WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym"
            );

            $stockMovements = DB::select(
                "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(quantity) as qty FROM inventory_transactions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym"
            );

            // TASK 13 PHASE 8 — 'repair_trends' was removed from this payload.
            // The three remaining series are the correct post-retirement
            // shape: a system with no Repair Request module must not report a
            // Repair Request metric.
            return [
                'damaged_trends' => $damaged,
                'dispatch_trends' => $dispatches,
                'stock_movements' => $stockMovements,
            ];
        });
    }

    private function timeSeries(string $table, string $dateColumn = 'created_at', int $months = 12, ?string $sumColumn = null, array $where = []): array
    {
        $params = [];
        $whereSql = '';
        foreach ($where as $k => $v) {
            $whereSql .= ' AND ' . $k . ' = ?';
            $params[] = $v;
        }

        // TASK 5 — the window is anchored to the FIRST DAY of the starting
        // month, not to CURDATE() minus N months.
        //
        // The old bound, DATE_SUB(CURDATE(), INTERVAL {$months} MONTH), starts
        // mid-month (on 2026-09-15 it began 2025-09-15). That produced
        // {$months}+1 buckets for a chart titled "Last 12 Months", and made the
        // first bucket a PARTIAL month — it was labelled "Sep '25" but silently
        // excluded Sep 1–14, so that column was not comparable to the 11 full
        // months beside it.
        //
        // Anchoring to the month boundary yields exactly {$months} whole
        // calendar-month buckets ending with the current month, which is what
        // the title claims and what the zero-fill below iterates over.
        $startMonth = (new \DateTimeImmutable('first day of this month'))
            ->modify('-' . ($months - 1) . ' month');

        $sql = "SELECT DATE_FORMAT({$dateColumn}, '%Y-%m') as ym, "
            . ($sumColumn ? "SUM({$sumColumn}) as val" : "COUNT(id) as val")
            . " FROM {$table} WHERE {$dateColumn} >= ? {$whereSql} GROUP BY ym ORDER BY ym";

        // Prepend the range bound so it binds before the $where params appended above.
        array_unshift($params, $startMonth->format('Y-m-d 00:00:00'));

        $rows = DB::select($sql, $params);

        // TASK 5 — zero-fill missing months.
        //
        // GROUP BY only emits a bucket for months that actually have rows, so
        // a month with no inventory activity was omitted from the series
        // entirely rather than plotted as 0. On the "Inventory Activity —
        // Last 12 Months" chart that made the x-axis skip straight from May
        // to July (June had no transactions), which reads as "June is
        // missing/unknown" instead of the truth, "June was zero", and
        // silently compressed a 12-month axis into however many months
        // happened to have data.
        //
        // This fabricates nothing: a month with no rows genuinely had zero
        // recorded activity. The buckets are exactly the {$months} whole
        // calendar months the query window above spans, so the filled series
        // matches the window the chart title states.
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[(string) $r->ym] = $r->val;
        }

        $filled = [];
        for ($i = 0; $i < $months; $i++) {
            $key      = $startMonth->modify("+{$i} month")->format('Y-m');
            $filled[] = ['ym' => $key, 'val' => $byMonth[$key] ?? 0];
        }

        return $filled;
    }

    public function topRequestedItems(array $filters = []): array
    {
        $cacheKey = 'analytics.topRequestedItems:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $q = DB::table('dispatch_items as di')
                ->join('items as i', 'i.id', '=', 'di.item_id')
                ->selectRaw('i.id as item_id, i.name, SUM(di.quantity) as total_requested')
                ->groupBy('i.id', 'i.name')
                ->orderByDesc('total_requested')
                ->limit(50);

            if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
                $q->join('dispatches as d', 'd.id', '=', 'di.dispatch_id');
                if (!empty($filters['date_from'])) $q->whereDate('d.created_at', '>=', $filters['date_from']);
                if (!empty($filters['date_to'])) $q->whereDate('d.created_at', '<=', $filters['date_to']);
            }

            return ['top_requested' => $q->get()];
        });
    }

    // TASK 13 PHASE 8 (Repair retirement) — topRepairedItems() was removed
    // here. Like repairReport(), repair_requests was its base table; the
    // items/damage_reports joins only decorated it, so there is no shared
    // inventory metric buried inside it. topRequestedItems() directly above
    // is the Inventory/Dispatch "most requested items" metric and is a
    // completely separate query — it is untouched.

    public function monthlyInventoryComparison(array $filters = []): array
    {
        // TASK 5 — v2: timeSeries() now zero-fills missing months (see its doc
        // comment). Bumped so pre-v2 cached payloads with gapped series are
        // not served for the remainder of their TTL.
        $cacheKey = 'analytics.monthlyInventoryComparison:v2:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $thisMonth = $this->timeSeries('inventory_transactions', 'created_at', 1, 'quantity');
            $last12 = $this->timeSeries('inventory_transactions', 'created_at', 12, 'quantity');
            return ['this_month' => $thisMonth, 'last_12_months' => $last12];
        });
    }

    public function departmentUsageComparison(array $filters = []): array
    {
        // Department attribution for inventory movement.
        //
        // This method previously joined `items.department_id`, a column that
        // does not exist and has never existed in any migration — so the
        // endpoint threw SQLSTATE[42S22] (Unknown column 'i.department_id')
        // on every call and the panel always rendered its error state.
        //
        // `inventory_transactions` has no department_id of its own. The only
        // department link that describes where stock actually MOVED is the
        // dispatch: inventory_transactions.dispatch_id → dispatches.department_id.
        // This mirrors semesterDetail()'s department breakdown, which already
        // attributes by dispatches.department_id for the same reason.
        //
        // Movements not tied to a dispatch (adjustments, returns, disposals,
        // direct deploys) genuinely have no owning department and group under
        // 'No Department' rather than being dropped or silently mis-attributed.
        $cacheKey = 'analytics.departmentUsageComparison:v2:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $q = DB::table('inventory_transactions as t')
                ->leftJoin('dispatches as ds', 'ds.id', '=', 't.dispatch_id')
                ->leftJoin('departments as d', 'd.department_id', '=', 'ds.department_id')
                ->selectRaw("d.department_id, COALESCE(d.name, 'No Department') as department_name, SUM(t.quantity) as total_moved")
                ->groupBy('d.department_id', 'd.name')
                ->orderByDesc('total_moved')
                ->limit(50);

            if (!empty($filters['date_from'])) $q->whereDate('t.created_at', '>=', $filters['date_from']);
            if (!empty($filters['date_to'])) $q->whereDate('t.created_at', '<=', $filters['date_to']);

            return ['department_usage' => $q->get()];
        });
    }

    public function semesterYearComparison(array $filters = []): array
    {
        $cacheKey = 'analytics.semesterYearComparison:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            // semester: Jan-Jun, Jul-Dec
            $sql = "SELECT CASE WHEN MONTH(created_at) BETWEEN 1 AND 6 THEN CONCAT(YEAR(created_at),'-S1') ELSE CONCAT(YEAR(created_at),'-S2') END AS period, SUM(quantity) as qty FROM inventory_transactions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR) GROUP BY period ORDER BY period";
            $rows = DB::select($sql);
            return ['semester_trends' => $rows];
        });
    }

    /**
     * Semester detail, sourced from the single school_settings row
     * (App\Models\SchoolSetting) — the SAME source of truth the Dashboard's
     * "Current Academic Session" card uses. There is only one configured
     * academic period at a time (no per-year history), so this method no
     * longer takes a $year parameter or assumes Jan–Jun / Jul–Dec calendar
     * splits. Returns counts for 3 metrics (maintenance reports, dispatches,
     * damage reports) split by the real 1st/2nd semester date ranges, plus a
     * department-level breakdown using damage_reports and dispatches.
     *
     * TASK 13 PHASE 8 (Repair retirement) — this is a SHARED method and was
     * NOT deleted. Only the 4th metric, 'repairs' => COUNT(repair_requests),
     * was dropped from each semester: a system with no Repair Request module
     * must not report a Repair Request metric. The department breakdown below
     * never counted repairs and is untouched.
     *
     * If the Administrator has not configured all four semester dates yet,
     * `configured` is false and no dates/counts are fabricated.
     */
    public function semesterDetail(): array
    {
        $settings = SchoolSetting::current();

        $s1From = optional($settings->first_sem_start)->toDateString();
        $s1To   = optional($settings->first_sem_end)->toDateString();
        $s2From = optional($settings->second_sem_start)->toDateString();
        $s2To   = optional($settings->second_sem_end)->toDateString();

        if (!$s1From || !$s1To || !$s2From || !$s2To) {
            return [
                'configured'           => false,
                'school_year'          => $settings->school_year,
                'semesters'            => ['s1' => null, 's2' => null],
                'department_breakdown' => [],
            ];
        }

        // TASK 13 PHASE 8 — v2: each semester no longer carries a 'repairs'
        // count. Bumped for the same reason as overview() above.
        $cacheKey = 'analytics.semesterDetail:v2:' . md5($s1From . $s1To . $s2From . $s2To);

        return $this->rememberWithTags($cacheKey, 60, function () use ($settings, $s1From, $s1To, $s2From, $s2To) {
            // Simple count helper — one lightweight query per (metric, semester)
            $count = fn(string $table, string $from, string $to): int =>
                (int) DB::table($table)
                    ->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to)
                    ->count();

            // Determine which semester is currently active
            $today = now()->toDateString();
            $currentSemester = 0;
            if ($today >= $s1From && $today <= $s1To) {
                $currentSemester = 1;
            } elseif ($today >= $s2From && $today <= $s2To) {
                $currentSemester = 2;
            }

            $s1 = [
                'label'               => 'First Semester',
                'start'               => $s1From,
                'end'                 => $s1To,
                'maintenance_reports' => $count('maintenance_reports', $s1From, $s1To),
                'dispatches'          => $count('dispatches',          $s1From, $s1To),
                'damage_reports'      => $count('damage_reports',      $s1From, $s1To),
            ];

            $s2 = [
                'label'               => 'Second Semester',
                'start'               => $s2From,
                'end'                 => $s2To,
                'maintenance_reports' => $count('maintenance_reports', $s2From, $s2To),
                'dispatches'          => $count('dispatches',          $s2From, $s2To),
                'damage_reports'      => $count('damage_reports',      $s2From, $s2To),
            ];

            // Department breakdown using the two tables that have department_id FK:
            //   damage_reports.department_id  →  departments.department_id
            //   dispatches.department_id      →  departments.department_id
            //
            // Uses DATE(created_at) BETWEEN ? AND ? range checks (not
            // YEAR()/MONTH()) because the configured semesters can — and in
            // the current configuration do — cross a calendar-year boundary
            // (e.g. First Semester Jul 2026 – Feb 2027).
            $deptRows = DB::select(
                "SELECT
                     COALESCE(dept.name, 'No Department') AS department_name,
                     SUM(CASE WHEN src = 'damage'   AND sem = 1 THEN cnt ELSE 0 END) AS s1_damage,
                     SUM(CASE WHEN src = 'damage'   AND sem = 2 THEN cnt ELSE 0 END) AS s2_damage,
                     SUM(CASE WHEN src = 'dispatch' AND sem = 1 THEN cnt ELSE 0 END) AS s1_dispatches,
                     SUM(CASE WHEN src = 'dispatch' AND sem = 2 THEN cnt ELSE 0 END) AS s2_dispatches
                 FROM (
                     SELECT department_id, 'damage' AS src, 1 AS sem, COUNT(*) AS cnt
                     FROM   damage_reports
                     WHERE  DATE(created_at) BETWEEN ? AND ?
                     GROUP  BY department_id

                     UNION ALL

                     SELECT department_id, 'damage' AS src, 2 AS sem, COUNT(*) AS cnt
                     FROM   damage_reports
                     WHERE  DATE(created_at) BETWEEN ? AND ?
                     GROUP  BY department_id

                     UNION ALL

                     SELECT department_id, 'dispatch' AS src, 1 AS sem, COUNT(*) AS cnt
                     FROM   dispatches
                     WHERE  DATE(created_at) BETWEEN ? AND ?
                     GROUP  BY department_id

                     UNION ALL

                     SELECT department_id, 'dispatch' AS src, 2 AS sem, COUNT(*) AS cnt
                     FROM   dispatches
                     WHERE  DATE(created_at) BETWEEN ? AND ?
                     GROUP  BY department_id
                 ) AS combined
                 LEFT JOIN departments dept ON dept.department_id = combined.department_id
                 GROUP  BY combined.department_id, dept.name
                 ORDER  BY (
                     SUM(CASE WHEN src = 'damage'   AND sem = 1 THEN cnt ELSE 0 END) +
                     SUM(CASE WHEN src = 'damage'   AND sem = 2 THEN cnt ELSE 0 END) +
                     SUM(CASE WHEN src = 'dispatch' AND sem = 1 THEN cnt ELSE 0 END) +
                     SUM(CASE WHEN src = 'dispatch' AND sem = 2 THEN cnt ELSE 0 END)
                 ) DESC
                 LIMIT  20",
                [$s1From, $s1To, $s2From, $s2To, $s1From, $s1To, $s2From, $s2To]
            );

            return [
                'configured'           => true,
                'school_year'          => $settings->school_year,
                'current_semester'     => $currentSemester,
                'semesters'            => ['s1' => $s1, 's2' => $s2],
                'department_breakdown' => array_map(fn($r) => (array) $r, $deptRows),
            ];
        });
    }

    public function inventoryHealthIndicators(array $filters = []): array
    {
        // TASK 78 — standardized on the canonical i.status column (see
        // inventorySummary() above). Also fixes a latent double-count: the
        // old COALESCE threshold check counted qty=0 items as BOTH low_stock
        // AND out_of_stock, since 0 <= any non-negative threshold. status
        // is mutually exclusive, so that overlap is gone.
        // TASK 5 — v3: scoped to item_type='inventory_stock'.
        //
        // total_items previously counted every row in `items` (34), while the
        // Inventory page's "Total Items" counts only warehouse stock (31, via
        // /api/inventory-stock which filters item_type='inventory_stock').
        // Two different populations were being shown under the same "Total
        // Items" label on two screens. Since this card is Inventory HEALTH —
        // a low/out-of-stock replenishment metric — the stock population is
        // the correct one: room_asset rows are deployed fixed assets that can
        // never be "low stock", and including them only inflated the
        // low_stock_percent denominator.
        //
        // This also completes the alignment TASK 78 started (see
        // inventorySummary() above, which already standardized the STATUS
        // column on the Inventory page's source of truth but left the
        // POPULATION unaligned). low_stock/out_of_stock counts are unchanged
        // by this scoping — every low/out row in this database is already
        // inventory_stock — so only total_items (34 -> 31) and the derived
        // percentage move.
        $cacheKey = 'analytics.inventoryHealth:v3:' . md5(json_encode($filters));
        return $this->rememberWithTags($cacheKey, 120, function () use ($filters) {
            $stockItems = fn () => DB::table('items')->where('item_type', 'inventory_stock');

            $totalItems = $stockItems()->count();
            $lowStock = $stockItems()->where('status', 'low_stock')->count();
            $outOfStock = $stockItems()->where('status', 'out_of_stock')->count();

            return [
                'total_items' => $totalItems,
                'low_stock_count' => $lowStock,
                'out_of_stock_count' => $outOfStock,
                'low_stock_percent' => $totalItems > 0 ? round(($lowStock / $totalItems) * 100, 2) : 0,
            ];
        });
    }
    /**
     * Comprehensive analytics dashboard data with real semester-based calculations.
     * Returns ALL data needed for the analytics dashboard in one call.
     * All values are calculated dynamically from database - NO hardcoded values.
     */
    public function dashboardOverview(array $filters = []): array
    {
        // Determine semester scope
        $schoolSettings = SchoolSetting::current();
        $semesterActive = $schoolSettings->isActive();
        $currentSemester = $schoolSettings->current_semester;

        // If no active semester, still allow filtered view
        $semesterStartDate = $semesterActive ? $schoolSettings->semester_started_at : null;

        // Get full semester date range for chart data (entire semester, not just to today)
        $semesterStartForCharts = null;
        $semesterEndForCharts = null;
        if ($currentSemester === 'First Semester') {
            $semesterStartForCharts = $schoolSettings->first_sem_start?->toDateString();
            $semesterEndForCharts = $schoolSettings->first_sem_end?->toDateString();
        } elseif ($currentSemester === 'Second Semester') {
            $semesterStartForCharts = $schoolSettings->second_sem_start?->toDateString();
            $semesterEndForCharts = $schoolSettings->second_sem_end?->toDateString();
        }

        // Get current semester stats
        $currentStats = $this->getReportStats($semesterStartDate, $currentSemester);

        // Get previous semester stats for comparison
        $previousStats = $this->getPreviousSemesterStats($schoolSettings);

        // Calculate comparisons
        $totalComparison = $this->calculateComparison(
            $currentStats['total_reports'] ?? 0,
            $previousStats['total_reports'] ?? 0
        );
        $pendingComparison = $this->calculateComparison(
            $currentStats['pending'] ?? 0,
            $previousStats['pending'] ?? 0
        );
        $inProgressComparison = $this->calculateComparison(
            $currentStats['in_progress'] ?? 0,
            $previousStats['in_progress'] ?? 0
        );
        $completedComparison = $this->calculateComparison(
            $currentStats['completed'] ?? 0,
            $previousStats['completed'] ?? 0
        );

        // Get inventory health
        $inventoryHealth = $this->inventoryHealthIndicators();

        // Get inventory comparisons
        $inventoryComparison = $this->getInventoryComparisons($schoolSettings, $semesterActive);

        // Get reports by status distribution
        $reportsByStatus = $this->getReportsByStatus($semesterStartDate);

        // Get reports by category (using full semester date range)
        $reportsByCategory = $this->getReportsByCategory($semesterStartForCharts, $semesterEndForCharts);

        // Get monthly trend
        $monthlyTrend = $this->getMonthlyReportsTrend($semesterStartDate, $currentSemester);

        // Get inventory status breakdown
        $inventoryStatus = $this->getInventoryStatusBreakdown();

        // Get damage reports by category
        $damageByCategory = $this->getDamageReportsByCategory($semesterStartDate);

        // Get dispatch status breakdown
        $dispatchStatus = $this->getDispatchStatusBreakdown($semesterStartDate);

        // Get items needing attention
        $itemsNeedingAttention = $this->getItemsNeedingAttention();

        return [
            'summary' => [
                'total_reports' => $currentStats['total_reports'] ?? 0,
                'total_reports_comparison' => $totalComparison,
                'pending' => $currentStats['pending'] ?? 0,
                'pending_comparison' => $pendingComparison,
                'in_progress' => $currentStats['in_progress'] ?? 0,
                'in_progress_comparison' => $inProgressComparison,
                'completed' => $currentStats['completed'] ?? 0,
                'completed_comparison' => $completedComparison,
                'semester_active' => $semesterActive,
                'current_semester' => $currentSemester,
                'school_year' => $schoolSettings->school_year,
            ],
            'inventory' => [
                'total_items' => $inventoryHealth['total_items'] ?? 0,
                'total_items_comparison' => $inventoryComparison['total_items_comparison'],
                'low_stock' => $inventoryHealth['low_stock_count'] ?? 0,
                'low_stock_comparison' => $inventoryComparison['low_stock_comparison'],
                'out_of_stock' => $inventoryHealth['out_of_stock_count'] ?? 0,
                'out_of_stock_comparison' => $inventoryComparison['out_of_stock_comparison'],
                'low_stock_percentage' => $inventoryHealth['low_stock_percent'] ?? 0,
                'low_stock_percentage_comparison' => $inventoryComparison['low_stock_percentage_comparison'],
            ],
            'charts' => [
                'reports_by_status' => $reportsByStatus,
                'reports_by_category' => $reportsByCategory,
                'monthly_trend' => $monthlyTrend,
                'inventory_status' => $inventoryStatus,
                'damage_by_category' => $damageByCategory,
                'dispatch_status' => $dispatchStatus,
            ],
            'items_needing_attention' => $itemsNeedingAttention,
        ];
    }

    /**
     * Get report statistics for the current/active semester.
     */
    private function getReportStats(?string $semesterStartDate, ?string $currentSemester): array
    {
        $query = DB::table('maintenance_reports')
            ->where(function ($q) {
                // Respect user authorization - show all for admins, own reports for others
                $user = auth()->user();
                if ($user && !in_array($user->role, ['super_admin', 'maintenance_admin'])) {
                    $userId = $user->user_id;
                    $q->where(function ($innerQ) use ($userId) {
                        $innerQ->where('created_by', $userId)
                            ->orWhere('assigned_to', $userId);
                    });
                }
            });

        if ($semesterStartDate && $currentSemester) {
            $query->where('created_at', '>=', $semesterStartDate);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total_reports,
            SUM(CASE WHEN status IN (\'submitted\', \'assigned\') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = \'in_progress\' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) as completed
        ')->first();

        return (array) $stats;
    }

    /**
     * Get statistics from the previous semester for comparison.
     */
    private function getPreviousSemesterStats(SchoolSetting $schoolSettings): array
    {
        // Determine previous semester dates
        $currentSemester = $schoolSettings->current_semester;

        if ($currentSemester === 'First Semester') {
            // Previous is second semester of last year - can't easily determine, return empty
            return [];
        } elseif ($currentSemester === 'Second Semester') {
            // Previous is first semester of this year
            $startDate = $schoolSettings->first_sem_start;
            $endDate = $schoolSettings->first_sem_end;
        } else {
            return [];
        }

        $query = DB::table('maintenance_reports')
            ->where(function ($q) {
                $user = auth()->user();
                if ($user && !in_array($user->role, ['super_admin', 'maintenance_admin'])) {
                    $userId = $user->user_id;
                    $q->where(function ($innerQ) use ($userId) {
                        $innerQ->where('created_by', $userId)
                            ->orWhere('assigned_to', $userId);
                    });
                }
            })
            ->whereBetween('created_at', [$startDate, $endDate]);

        $stats = $query->selectRaw('
            COUNT(*) as total_reports,
            SUM(CASE WHEN status IN (\'submitted\', \'assigned\') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = \'in_progress\' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) as completed
        ')->first();

        return (array) $stats;
    }

    /**
     * Calculate percentage change between two values.
     * Returns array with percentage, arrow, and semantic meaning.
     */
    private function calculateComparison(int $current, int $previous): array
    {
        if ($previous == 0) {
            if ($current == 0) {
                return ['value' => '0%', 'arrow' => '→', 'color' => 'gray', 'change' => 0];
            }
            return ['value' => 'New', 'arrow' => '↑', 'color' => 'green', 'change' => 0];
        }

        $percentage = (($current - $previous) / abs($previous)) * 100;
        $rounded = round($percentage, 0);

        if ($rounded >= 0) {
            $arrow = '↑';
            $color = 'green'; // Increase is generally positive
        } else {
            $arrow = '↓';
            $color = 'red'; // Decrease is generally negative
        }

        return [
            'value' => ($rounded >= 0 ? '+' : '') . $rounded . '%',
            'arrow' => $arrow,
            'color' => $color,
            'change' => $rounded,
        ];
    }

    /**
     * Get inventory comparisons between semesters.
     * Note: Since the items table doesn't track historical status changes, comparisons
     * are based on inventory_transactions data when available. If no historical data
     * is available for the previous semester, neutral comparisons (0% change) are returned.
     */
    private function getInventoryComparisons(SchoolSetting $schoolSettings, bool $semesterActive): array
    {
        $currentHealth = $this->inventoryHealthIndicators();

        // Get previous semester dates
        $currentSemester = $schoolSettings->current_semester;
        $prevLowStock = 0;
        $prevOutOfStock = 0;
        $prevTotal = 0;

        if ($currentSemester === 'Second Semester' && $schoolSettings->first_sem_start && $schoolSettings->first_sem_end) {
            // Previous is First Semester of this year
            // Calculate based on items that existed during that period
            $firstSemStart = $schoolSettings->first_sem_start;
            $firstSemEnd = $schoolSettings->first_sem_end;

            // Get distinct items from transactions during first semester
            $itemsInFirstSem = DB::table('inventory_transactions')
                ->whereDate('created_at', '>=', $firstSemStart)
                ->whereDate('created_at', '<=', $firstSemEnd)
                ->distinct('item_id')
                ->pluck('item_id');

            if ($itemsInFirstSem->isNotEmpty()) {
                // Get status of items that were in inventory during first semester
                $prevItems = DB::table('items')
                    ->whereIn('id', $itemsInFirstSem)
                    ->where('item_type', 'inventory_stock')
                    ->get();

                $prevTotal = $prevItems->count();
                $prevLowStock = $prevItems->where('status', 'low_stock')->count();
                $prevOutOfStock = $prevItems->where('status', 'out_of_stock')->count();
            }
        }

        // If no previous semester data found, use same current values for neutral comparison
        if ($prevTotal === 0) {
            $prevTotal = $currentHealth['total_items'] ?? 0;
            $prevLowStock = $currentHealth['low_stock_count'] ?? 0;
            $prevOutOfStock = $currentHealth['out_of_stock_count'] ?? 0;
        }

        $prevLowStockPercent = $prevTotal > 0
            ? round(($prevLowStock / $prevTotal) * 100, 2)
            : 0;

        return [
            'total_items_comparison' => $this->calculateComparison(
                $currentHealth['total_items'] ?? 0,
                $prevTotal
            ),
            'low_stock_comparison' => $this->calculateComparison(
                $currentHealth['low_stock_count'] ?? 0,
                $prevLowStock
            ),
            'out_of_stock_comparison' => $this->calculateComparison(
                $currentHealth['out_of_stock_count'] ?? 0,
                $prevOutOfStock
            ),
            'low_stock_percentage_comparison' => $this->calculateComparison(
                (int) ($currentHealth['low_stock_percent'] ?? 0),
                (int) $prevLowStockPercent
            ),
        ];
    }

    /**
     * Get distribution of reports by status.
     */
    private function getReportsByStatus(?string $semesterStartDate): array
    {
        $query = DB::table('maintenance_reports')
            ->where(function ($q) {
                $user = auth()->user();
                if ($user && !in_array($user->role, ['super_admin', 'maintenance_admin'])) {
                    $userId = $user->user_id;
                    $q->where(function ($innerQ) use ($userId) {
                        $innerQ->where('created_by', $userId)
                            ->orWhere('assigned_to', $userId);
                    });
                }
            });

        if ($semesterStartDate) {
            $query->where('created_at', '>=', $semesterStartDate);
        }

        // Map individual statuses to grouped categories
        $results = $query->selectRaw('
            CASE
                WHEN status IN (\'submitted\', \'assigned\') THEN \'Pending / Open\'
                WHEN status = \'in_progress\' THEN \'In Progress\'
                WHEN status = \'completed\' THEN \'Completed\'
                ELSE \'Other\'
            END as status_label,
            COUNT(*) as count
        ')
        ->groupBy('status_label')
        ->get();

        return $results->map(function ($row) {
            return [
                'label' => $row->status_label,
                'count' => (int) $row->count,
            ];
        })->toArray();
    }

    /**
     * Get distribution of reports by category/problem type.
     * Shows all reports for the semester in the analytics dashboard.
     */
    private function getReportsByCategory(?string $semesterStartDate, ?string $semesterEndDate = null): array
    {
        $query = DB::table('maintenance_reports')
            ->whereNotNull('problem_type');

        if ($semesterStartDate) {
            $query->whereDate('created_at', '>=', $semesterStartDate);
        }

        if ($semesterEndDate) {
            $query->whereDate('created_at', '<=', $semesterEndDate);
        }

        $results = $query->selectRaw('
            problem_type,
            COUNT(*) as count
        ')
        ->groupBy('problem_type')
        ->orderByDesc('count')
        ->limit(10)
        ->get();

        return $results->map(function ($row) {
            return [
                'label' => $row->problem_type,
                'count' => (int) $row->count,
            ];
        })->toArray();
    }

    /**
     * Get monthly trend of reports created.
     */
    private function getMonthlyReportsTrend(?string $semesterStartDate, ?string $currentSemester): array
    {
        $query = DB::table('maintenance_reports')
            ->where(function ($q) {
                $user = auth()->user();
                if ($user && !in_array($user->role, ['super_admin', 'maintenance_admin'])) {
                    $userId = $user->user_id;
                    $q->where(function ($innerQ) use ($userId) {
                        $innerQ->where('created_by', $userId)
                            ->orWhere('assigned_to', $userId);
                    });
                }
            });

        if ($semesterStartDate && $currentSemester) {
            $query->where('created_at', '>=', $semesterStartDate);
        }

        $results = $query->selectRaw('
            DATE_FORMAT(created_at, \'%Y-%m\') as month,
            COUNT(*) as count
        ')
        ->groupBy('month')
        ->orderBy('month')
        ->get();

        return $results->map(function ($row) {
            return [
                'month' => $row->month,
                'count' => (int) $row->count,
            ];
        })->toArray();
    }

    /**
     * Get breakdown of inventory by status.
     */
    private function getInventoryStatusBreakdown(): array
    {
        $results = DB::table('items')
            ->where('item_type', 'inventory_stock')
            ->selectRaw('
                CASE
                    WHEN status = \'available\' THEN \'In Stock\'
                    WHEN status = \'low_stock\' THEN \'Low Stock\'
                    WHEN status = \'out_of_stock\' THEN \'Out of Stock\'
                    ELSE \'Other\'
                END as status_label,
                COUNT(*) as count
            ')
            ->groupBy('status_label')
            ->get();

        return $results->map(function ($row) {
            return [
                'label' => $row->status_label,
                'count' => (int) $row->count,
            ];
        })->toArray();
    }

    /**
     * Get damage reports grouped by category.
     */
    private function getDamageReportsByCategory(?string $semesterStartDate): array
    {
        $query = DB::table('damage_reports as d')
            ->join('items as i', 'i.id', '=', 'd.item_id')
            ->where(function ($q) {
                $user = auth()->user();
                if ($user && !in_array($user->role, ['super_admin', 'maintenance_admin'])) {
                    $userId = $user->user_id;
                    $q->where('d.reported_by', $userId);
                }
            });

        if ($semesterStartDate) {
            $query->where('d.created_at', '>=', $semesterStartDate);
        }

        $results = $query->selectRaw('
            COALESCE(ic.name, \'Uncategorized\') as category,
            COUNT(d.id) as count
        ')
        ->leftJoin('inventory_categories as ic', 'i.category_id', '=', 'ic.id')
        ->groupBy('category')
        ->orderByDesc('count')
        ->limit(10)
        ->get();

        return $results->map(function ($row) {
            return [
                'label' => $row->category,
                'count' => (int) $row->count,
            ];
        })->toArray();
    }

    /**
     * Get dispatch status breakdown.
     */
    private function getDispatchStatusBreakdown(?string $semesterStartDate): array
    {
        $query = DB::table('dispatches');

        if ($semesterStartDate) {
            $query->where('created_at', '>=', $semesterStartDate);
        }

        $results = $query->selectRaw('
            CASE
                WHEN status = \'pending\' THEN \'Pending\'
                WHEN status = \'approved\' THEN \'Assigned\'
                WHEN status = \'released\' THEN \'In Transit\'
                WHEN status = \'completed\' THEN \'Completed\'
                ELSE CONCAT(\'Other (\', status, \')\')
            END as status_label,
            COUNT(*) as count
        ')
        ->groupBy('status_label')
        ->get();

        return $results->map(function ($row) {
            return [
                'label' => $row->status_label,
                'count' => (int) $row->count,
            ];
        })->toArray();
    }

    /**
     * Get inventory items that need attention (low stock or out of stock).
     */
    private function getItemsNeedingAttention(): array
    {
        $results = DB::table('items')
            ->where('item_type', 'inventory_stock')
            ->whereIn('status', ['low_stock', 'out_of_stock'])
            ->selectRaw('
                id,
                name,
                quantity,
                reorder_level as threshold,
                status,
                CASE
                    WHEN status = \'out_of_stock\' THEN 1
                    WHEN status = \'low_stock\' THEN 2
                    ELSE 3
                END as severity
            ')
            ->orderBy('severity')
            ->orderBy('quantity')
            ->limit(20)
            ->get();

        return $results->map(function ($row) {
            return [
                'id' => $row->id,
                'name' => $row->name,
                'quantity' => (int) $row->quantity,
                'threshold' => (int) $row->threshold,
                'status' => $row->status,
            ];
        })->toArray();
    }

    private function rememberWithTags(string $key, int $ttl, callable $callback)
    {
        try {
            return Cache::tags(['analytics'])->remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            return Cache::remember($key, $ttl, $callback);
        }
    }

}
