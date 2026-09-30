<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Services\InventoryAdjustmentService;
use App\Services\InventoryLowStockNotifier;
use App\Services\InventoryStatusService;
use App\Services\RoleNormalizerService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// NOTE: stock creation (store, createEntry) has been deprecated.
// All new stock must flow through PurchaseReceiptController (OR-based receipts).
// This controller still handles: listing, adjustments, deploys, and destroy.
class InventoryStockController extends Controller
{
    use ApiResponder;

    private const READ_ROLES  = ['super_admin', 'maintenance_admin', 'maintenance_staff'];
    private const WRITE_ROLES = ['super_admin', 'maintenance_admin'];

    public function __construct(
        private readonly InventoryAdjustmentService $inventoryAdjustmentService,
        // TASK 18 — notify once on a genuine NORMAL -> LOW/OUT_OF_STOCK transition.
        private readonly InventoryLowStockNotifier $inventoryLowStockNotifier
    ) {
    }

    // ─── Session / auth helpers ───────────────────────────────────────────────

    private function sessionUser(Request $request): array
    {
        return (array) $request->session()->get('auth_user',
            $request->session()->get('user', [])
        );
    }

    private function sessionUserId(Request $request): int
    {
        return (int) ($this->sessionUser($request)['user_id'] ?? 0);
    }

    private function resolveRole(Request $request): string
    {
        return RoleNormalizerService::normalize($this->sessionUser($request)['role'] ?? '');
    }

    private function requireWriteAccess(Request $request): void
    {
        if (! in_array($this->resolveRole($request), self::WRITE_ROLES, true)) {
            abort(403, 'Insufficient permissions');
        }
    }

    // ─── Shared helpers ───────────────────────────────────────────────────────

    /**
     * Finds an existing inventory_stock item by name / category / unit_type,
     * optionally also by brand / model.
     * Two-pass: exact match first, then fallback ignoring unit_type / brand / model.
     * Mirrors findExistingInventoryStockForEntry() in the legacy PHP.
     *
     * TASK 6B PHASE 2 — the lookup no longer scopes to an inventory room.
     * Inventory is a single centralized pool, so an item's identity is its
     * name/category/unit fingerprint alone. The Phase 1 audit verified there
     * are ZERO cross-room name collisions among inventory_stock items today,
     * so widening the scope cannot start flagging two pre-existing rows as
     * duplicates of each other — it only prevents NEW duplicates.
     */
    private function findExistingStock(
        string  $name,
        ?int    $categoryId,
        string  $unitType,
        ?string $brand = null,
        ?string $model = null,
    ): ?array {
        // Primary: name + category + unit_type + brand + model
        $row = DB::selectOne(
            "SELECT * FROM items
             WHERE item_type = 'inventory_stock'
               AND LOWER(name) = LOWER(?)
               AND LOWER(COALESCE(unit_type,'')) = LOWER(?)
               AND ((category_id IS NULL AND ? IS NULL) OR category_id = ?)
               AND LOWER(COALESCE(brand,'')) = LOWER(COALESCE(?,''))
               AND LOWER(COALESCE(model,'')) = LOWER(COALESCE(?,''))
             LIMIT 1",
            [$name, $unitType, $categoryId, $categoryId,
             $brand ?? '', $model ?? '']
        );
        if ($row) return (array) $row;

        // Fallback: ignore unit_type / brand / model (used by create_entry path)
        $row = DB::selectOne(
            "SELECT * FROM items
             WHERE item_type = 'inventory_stock'
               AND LOWER(name) = LOWER(?)
               AND ((category_id IS NULL AND ? IS NULL) OR category_id = ?)
             LIMIT 1",
            [$name, $categoryId, $categoryId]
        );
        return $row ? (array) $row : null;
    }

    /** Casts the integer columns returned as strings by PDO. */
    private function castItemInts(array $row): array
    {
        static $intCols = [
            'id', 'category_id', 'quantity',
            'reserved_quantity', 'reorder_level', 'low_stock_warning', 'available_quantity',
        ];
        foreach ($intCols as $col) {
            if (array_key_exists($col, $row) && $row[$col] !== null) {
                $row[$col] = (int) $row[$col];
            }
        }
        return $row;
    }

    // ─── SECTION 1 ── index(), summary() ─────────────────────────────────────

    /**
     * GET /api/inventory-stock
     * Returns ALL matching inventory_stock items (no pagination).
     * Filters: category_id, status, search, condition.
     */
    public function index(Request $request): JsonResponse
    {
        $categoryId      = $request->filled('category_id')
            ? (int) $request->integer('category_id') : null;
        $status          = $request->filled('status')
            ? $request->string('status')->toString() : null;
        $search          = $request->filled('search')
            ? $request->string('search')->toString() : null;
        $condition       = $request->filled('condition')
            ? $request->string('condition')->toString() : null;

        $sql = "SELECT i.id, i.name, i.unit_type, i.brand, i.model, i.item_condition,
                       i.category_id,      c.name  AS category_name,
                       i.status, i.quantity, i.reserved_quantity, i.reorder_level,
                       i.description, i.created_at, i.updated_at,
                       CASE
                           WHEN i.quantity <= 0               THEN 0
                           WHEN i.quantity <= i.reorder_level THEN 1
                           ELSE 0
                       END AS low_stock_warning,
                       GREATEST(i.quantity - i.reserved_quantity, 0) AS available_quantity
                FROM items i
                LEFT JOIN inventory_categories c  ON i.category_id      = c.id
                WHERE i.item_type = 'inventory_stock'";

        $params = [];

        if ($categoryId !== null && $categoryId > 0) {
            $sql     .= ' AND i.category_id = ?';
            $params[] = $categoryId;
        }
        if ($status !== null && $status !== '') {
            $sql     .= ' AND i.status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $sql .= ' AND (i.name LIKE ? OR i.brand LIKE ? OR i.model LIKE ?
                          OR i.description LIKE ? OR i.unit_type LIKE ?
                          OR i.item_condition LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($condition !== null && $condition !== '') {
            $sql     .= " AND LOWER(COALESCE(i.item_condition, '')) = LOWER(?)";
            $params[] = $condition;
        }

        $sql .= ' ORDER BY i.name ASC';

        $items = array_map(
            fn ($row) => $this->castItemInts((array) $row),
            DB::select($sql, $params)
        );

        return $this->ok('Inventory stock retrieved', ['items' => $items]);
    }

    /**
     * GET /api/inventory-stock/summary
     * Aggregate counts + 5 most recent deployments + pending restock count.
     */
    public function summary(): JsonResponse
    {
        $totals = DB::selectOne(
            "SELECT
                COUNT(*) AS total_stock_items,
                COALESCE(SUM(GREATEST(quantity - reserved_quantity, 0)), 0) AS total_units_available,
                SUM(CASE WHEN status = 'low_stock'    THEN 1 ELSE 0 END) AS low_stock_count,
                SUM(CASE WHEN status = 'out_of_stock' THEN 1 ELSE 0 END) AS out_of_stock_count
             FROM items
             WHERE item_type = 'inventory_stock'"
        );

        $recentDeployments = DB::select(
            "SELECT it.item_id, i.name AS item_name, r.name AS room_name,
                    it.quantity, it.created_at
             FROM inventory_transactions it
             LEFT JOIN items i ON it.item_id = i.id
             LEFT JOIN rooms r ON it.room_id = r.id
             WHERE it.transaction_type = 'deploy'
             ORDER BY it.created_at DESC
             LIMIT 5"
        );

        $restockRow = DB::selectOne(
            "SELECT COUNT(*) AS cnt
             FROM restock_requests
             WHERE status IN ('open', 'approved')"
        );

        return $this->ok('Stock summary retrieved', [
            'total_stock_items'        => (int) ($totals->total_stock_items     ?? 0),
            'total_units_available'    => (int) ($totals->total_units_available ?? 0),
            'low_stock_count'          => (int) ($totals->low_stock_count       ?? 0),
            'out_of_stock_count'       => (int) ($totals->out_of_stock_count    ?? 0),
            'recent_deployments'       => array_map(fn ($r) => (array) $r, $recentDeployments),
            'pending_restock_requests' => (int) ($restockRow->cnt              ?? 0),
        ]);
    }

    // ─── SECTION 2 ── transactions(), listEntries() ───────────────────────────

    /**
     * GET /api/inventory-stock/{id}/transactions
     * Transaction history for a stock item (newest-first, limit 100).
     * {id} = item_id (required). Optional query param: ?room_id= to narrow further.
     */
    public function transactions(Request $request, int $id): JsonResponse
    {
        if ($id <= 0) {
            return $this->fail('item_id is required', 400);
        }

        $roomId = $request->filled('room_id')
            ? (int) $request->integer('room_id') : null;

        $sql = "SELECT it.id, it.item_id,        i.name        AS item_name,
                       it.room_id,                r.name        AS room_name,
                       it.transaction_type, it.quantity, it.reference_note,
                       it.performed_by,           u.full_name   AS performed_by_name,
                       it.report_id, it.created_at, it.updated_at
                FROM inventory_transactions it
                LEFT JOIN users u ON it.performed_by = u.user_id
                LEFT JOIN items i ON it.item_id      = i.id
                LEFT JOIN rooms r ON it.room_id      = r.id
                WHERE it.item_id = ?";

        $params = [$id];

        if ($roomId !== null && $roomId > 0) {
            $sql     .= ' AND it.room_id = ?';
            $params[] = $roomId;
        }

        $sql .= ' ORDER BY it.created_at DESC LIMIT 100';

        $transactions = array_map(
            fn ($row) => (array) $row,
            DB::select($sql, $params)
        );

        return $this->ok('Transaction history retrieved', ['transactions' => $transactions]);
    }

    /**
     * GET /api/inventory-stock/entries
     * Lists inventory_stock_entries rows (hard limit 200, newest-first).
     * Filters: search, category_id, department_id, room_id,
     *          receiver_user_id, date_from, date_to (YYYY-MM-DD).
     */
    public function listEntries(Request $request): JsonResponse
    {
        $search         = $request->filled('search')
            ? $request->string('search')->toString() : '';
        $categoryId     = $request->filled('category_id')
            ? (int) $request->integer('category_id') : null;
        $departmentId   = $request->filled('department_id')
            ? (int) $request->integer('department_id') : null;
        $roomId         = $request->filled('room_id')
            ? (int) $request->integer('room_id') : null;
        $receiverUserId = $request->filled('receiver_user_id')
            ? (int) $request->integer('receiver_user_id') : null;
        $dateFrom       = $this->parseEntryDate($request->string('date_from', '')->toString());
        $dateTo         = $this->parseEntryDate($request->string('date_to',   '')->toString());

        $sql = "SELECT ise.id, ise.stock_entry_id, ise.or_number, ise.supplier_name,
                       ise.date_received,
                       ise.item_id,
                       ise.category_id,         c.name AS category_name,
                       ise.department_id,        d.name AS department_name,
                       ise.room_id,              r.name AS room_name,
                       ise.receiver_user_id,     u.full_name AS receiver_name,
                       ise.item_name, ise.quantity, ise.unit_type,
                       ise.description, ise.item_condition,
                       ise.created_at, ise.updated_at
                FROM inventory_stock_entries ise
                INNER JOIN users             u  ON u.user_id         = ise.receiver_user_id
                LEFT JOIN  inventory_categories c ON c.id            = ise.category_id
                LEFT JOIN  departments       d  ON d.department_id   = ise.department_id
                LEFT JOIN  rooms             r  ON r.id              = ise.room_id
                WHERE 1 = 1";

        $params = [];

        if ($search !== '') {
            $sql .= " AND (ise.stock_entry_id LIKE ?
                        OR ise.or_number      LIKE ?
                        OR ise.supplier_name  LIKE ?
                        OR ise.item_name      LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($categoryId !== null && $categoryId > 0) {
            $sql     .= ' AND ise.category_id = ?';
            $params[] = $categoryId;
        }
        if ($departmentId !== null && $departmentId > 0) {
            $sql     .= ' AND ise.department_id = ?';
            $params[] = $departmentId;
        }
        if ($roomId !== null && $roomId > 0) {
            $sql     .= ' AND ise.room_id = ?';
            $params[] = $roomId;
        }
        if ($receiverUserId !== null && $receiverUserId > 0) {
            $sql     .= ' AND ise.receiver_user_id = ?';
            $params[] = $receiverUserId;
        }
        if ($dateFrom !== null) {
            $sql     .= ' AND ise.date_received >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== null) {
            $sql     .= ' AND ise.date_received <= ?';
            $params[] = $dateTo;
        }

        $sql .= ' ORDER BY ise.date_received DESC, ise.created_at DESC, ise.id DESC LIMIT 200';

        $entries = array_map(
            fn ($row) => (array) $row,
            DB::select($sql, $params)
        );

        return $this->ok('Stock entries retrieved', ['entries' => $entries]);
    }

    /**
     * Validates a YYYY-MM-DD date string.
     * Returns the string unchanged if valid, null for blank / malformed input.
     * Mirrors validateInventoryEntryDate() in the legacy PHP.
     */
    private function parseEntryDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $raw);
        return ($d && $d->format('Y-m-d') === $raw) ? $raw : null;
    }

    // ─── SECTION 3 ── store(), createEntry() ─────────────────────────────────

    /**
     * POST /api/inventory-stock
     * Creates a new inventory_stock item, or merges quantity into an existing
     * matching item (same name + category + unit_type + brand + model).
     * Returns 201 on fresh create, 200 on merge-add.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->fail(
            'This endpoint is deprecated. Use POST /api/purchase-receipts and POST /api/purchase-receipts/{id}/post to add stock.',
            410
        );
    }

    /**
     * POST /api/inventory-stock/entries
     * Creates an inventory_stock_entries record, finds-or-creates the
     * matching inventory_stock item, and logs an adjustment transaction.
     */
    public function createEntry(Request $request): JsonResponse
    {
        return $this->fail(
            'This endpoint is deprecated. Use POST /api/purchase-receipts and POST /api/purchase-receipts/{id}/post to add stock.',
            410
        );
    }

    // ─── Private helpers (first used in Section 3) ───────────────────────────

    /**
     * Fetches a single inventory_stock item with available_quantity computed.
     * Mirrors fetchInventoryStockItemById() in the legacy PHP.
     */
    private function fetchStockItemById(int $id): ?array
    {
        $row = DB::selectOne(
            "SELECT i.*,
                    GREATEST(i.quantity - i.reserved_quantity, 0) AS available_quantity
             FROM items i
             WHERE i.id = ? AND i.item_type = 'inventory_stock'
             LIMIT 1",
            [$id]
        );

        if (! $row) {
            return null;
        }

        $r = (array) $row;
        foreach (['id', 'category_id', 'quantity',
                  'reserved_quantity', 'reorder_level', 'available_quantity'] as $col) {
            if (array_key_exists($col, $r) && $r[$col] !== null) {
                $r[$col] = (int) $r[$col];
            }
        }
        return $r;
    }

    /**
     * Generates a collision-resistant stock entry ID (ISE-YYYYMMDD-XXXXXXXX).
     * Retries on the rare chance of a collision; falls back to timestamp + suffix.
     */
    private function generateStockEntryId(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'ISE-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            if (! DB::selectOne(
                "SELECT id FROM inventory_stock_entries WHERE stock_entry_id = ? LIMIT 1",
                [$candidate]
            )) {
                return $candidate;
            }
        }
        // Guaranteed-unique fallback
        return 'ISE-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    /**
     * Builds the reference note stored on the adjustment transaction.
     * Mirrors buildInventoryEntryReferenceNote() in the legacy PHP.
     */
    private function buildEntryReferenceNote(string $stockEntryId, string $orNumber, string $supplierName): string
    {
        return "Stock entry {$stockEntryId} — OR#{$orNumber} from {$supplierName}";
    }

    // ─── SECTION 4 ── update(), destroy() ────────────────────────────────────

    /**
     * PUT /api/inventory-stock/{id}
     * Partial-or-full update of a stock item's metadata only (name, unit type,
     * condition, room, category, brand, model, reorder level, description).
     * Every field is optional — omitted fields fall back to the current DB value.
     * Quantity is never accepted here; it can only change via Manual Stock
     * Adjustment (see InventoryAdjustmentService).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireWriteAccess($request);

        if ($id <= 0) {
            return $this->fail('id is required', 400);
        }

        // 'sometimes' = skip validation entirely when the key is absent from the request.
        // This lets us distinguish "not sent" from "sent as null/0".
        $validated = $request->validate([
            'name'              => ['sometimes', 'string', 'max:255'],
            'unit_type'         => ['sometimes', 'string', 'max:100'],
            'item_condition'    => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id'       => ['sometimes', 'nullable', 'integer', 'min:1',
                                    'exists:inventory_categories,id'],
            'reorder_level'     => ['sometimes', 'integer', 'min:0'],
            'description'       => ['sometimes', 'nullable', 'string', 'max:1000'],
            'brand'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'model'             => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        DB::beginTransaction();
        try {
            $existing = $this->fetchStockItemById($id);
            if (! $existing) {
                throw new \RuntimeException('Inventory stock item not found');
            }

            // ── Resolve each field: incoming value if sent, else keep current ──
            //    Mirrors array_key_exists() checks in the legacy PHP.

            $name = $request->has('name')
                ? trim((string) $validated['name'])
                : (string) $existing['name'];

            // Quantity is intentionally never accepted from this endpoint — Manual
            // Stock Adjustment (InventoryAdjustmentService, via items/{id}/adjust-stock)
            // is the only sanctioned path for changing on-hand quantity, so it goes
            // through InventoryTransaction -> InventoryTransactionObserver with proper
            // ledger history, activity logging, and analytics cache invalidation.
            $quantity = (int) $existing['quantity'];

            $reorderLevel = $request->has('reorder_level')
                ? (int) $validated['reorder_level']
                : (int) ($existing['reorder_level'] ?? 0);

            $description = $request->has('description')
                ? trim((string) ($validated['description'] ?? ''))
                : trim((string) ($existing['description'] ?? ''));

            // category_id: explicit null clears it; absent = keep existing
            if ($request->has('category_id')) {
                $categoryId = $validated['category_id'] !== null
                    ? (int) $validated['category_id']
                    : null;
            } else {
                $categoryId = $existing['category_id'] !== null
                    ? (int) $existing['category_id']
                    : null;
            }

            $unitType = $request->has('unit_type')
                ? strtolower(trim((string) $validated['unit_type']))
                : strtolower(trim((string) ($existing['unit_type'] ?? '')));

            $brand = $request->has('brand')
                ? trim((string) ($validated['brand'] ?? ''))
                : trim((string) ($existing['brand'] ?? ''));

            $model = $request->has('model')
                ? trim((string) ($validated['model'] ?? ''))
                : trim((string) ($existing['model'] ?? ''));

            $itemCondition = $request->has('item_condition')
                ? strtolower(trim((string) ($validated['item_condition'] ?? '')))
                : strtolower(trim((string) ($existing['item_condition'] ?? '')));

            // ── Semantic validations ──────────────────────────────────────────

            if ($name === '' || $unitType === '') {
                throw new \InvalidArgumentException('Invalid stock update payload');
            }

            // Duplicate check: same fingerprint on a DIFFERENT item
            $duplicate = $this->findExistingStock(
                $name, $categoryId, $unitType,
                $brand !== '' ? $brand : null,
                $model !== '' ? $model : null,
            );
            if ($duplicate && (int) $duplicate['id'] !== $id) {
                throw new \InvalidArgumentException(
                    'An item with the same name, category, brand, model, and unit type already exists in inventory'
                );
            }

            // ── Persist ───────────────────────────────────────────────────────

            $status = InventoryStatusService::deriveStatus($quantity, $reorderLevel);

            DB::update(
                "UPDATE items
                 SET name = ?, quantity = ?, reorder_level = ?, description = ?,
                     category_id = ?, unit_type = ?,
                     brand = ?, model = ?, item_condition = ?, status = ?, updated_at = NOW()
                 WHERE id = ? AND item_type = 'inventory_stock'",
                [
                    $name,
                    $quantity,
                    $reorderLevel,
                    $description !== '' ? $description : null,
                    $categoryId,
                    $unitType,
                    $brand !== '' ? $brand : null,
                    $model !== '' ? $model : null,
                    $itemCondition,
                    $status,
                    $id,
                ]
            );

            // TASK 18 — fires only on a genuine NORMAL -> LOW/OUT_OF_STOCK transition.
            $this->inventoryLowStockNotifier->handleStatusChange(
                $id,
                $name,
                $existing['status'] ?? null,
                $status
            );

            DB::commit();

            return $this->ok('Stock item updated successfully', ['item' => $this->fetchStockItemById($id)]);

        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return $this->fail($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            $code = $e->getMessage() === 'Inventory stock item not found' ? 404 : 400;
            return $this->fail($e->getMessage(), $code);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * DELETE /api/inventory-stock/{id}
     * Deletes a stock item. Blocked if reserved_quantity > 0 or
     * report_inventory_allocations records reference the item.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireWriteAccess($request);
        $performedBy = $this->sessionUserId($request);

        if ($id <= 0) {
            return $this->fail('id is required', 400);
        }

        DB::beginTransaction();
        try {
            $existing = $this->fetchStockItemById($id);
            if (! $existing) {
                throw new \RuntimeException('Inventory stock item not found');
            }

            if ($existing['reserved_quantity'] > 0) {
                throw new \InvalidArgumentException(
                    'This item cannot be deleted while stock is still reserved.'
                );
            }

            $allocations = DB::selectOne(
                "SELECT COUNT(*) AS cnt FROM report_inventory_allocations WHERE item_id = ?",
                [$id]
            );
            if ((int) ($allocations->cnt ?? 0) > 0) {
                throw new \InvalidArgumentException(
                    'This item already has inventory allocations and cannot be deleted.'
                );
            }

            $deleted = DB::delete(
                "DELETE FROM items WHERE id = ? AND item_type = 'inventory_stock'",
                [$id]
            );

            if ($deleted < 1) {
                throw new \RuntimeException('Inventory stock item not found');
            }

            // Activity log — inside the transaction so it rolls back on failure.
            // logInventoryActivity() silently no-ops if the schema doesn't match.
            if ($performedBy > 0) {
                $this->logInventoryActivity($performedBy, 'DELETE_INVENTORY_ITEM', $id, [
                    'item_name'         => $existing['name'] ?? '',
                    'category_id'       => $existing['category_id'],
                ]);
            }

            DB::commit();

            return $this->ok('Stock item deleted successfully');

        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            return $this->fail($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return $this->fail($e->getMessage(), 404);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ─── Private helper (first used in Section 4) ─────────────────────────────

    /**
     * Writes an audit record to activity_logs.
     * Mirrors logInventoryActivity() in the legacy PHP.
     * Wrapped in a try/catch so a schema mismatch never breaks the calling operation.
     */
    private function logInventoryActivity(
        int    $performedBy,
        string $action,
        int    $subjectId,
        array  $data = [],
    ): void {
        try {
            DB::insert(
                "INSERT INTO activity_logs
                     (user_id, action, subject_type, subject_id, data, created_at, updated_at)
                 VALUES (?, ?, 'inventory_item', ?, ?, NOW(), NOW())",
                [$performedBy, $action, $subjectId, json_encode($data)]
            );
        } catch (\Throwable) {
            // Non-critical — audit failure must never roll back or block the main operation
        }
    }

    // ─── SECTION 5 ── adjust() ────────────────────────────────────────────────
    // TASK 36 PHASE 7 — deploy() (and its exclusive private helpers
    // createOrUpdateRoomAsset(), fetchRoomAssetById(), syncDeploymentAllocation())
    // were retired here as part of the full Deploy-to-Room feature retirement.
    // See TASK_36_PHASE_7_DEPLOY_TO_ROOM_FULL_RETIREMENT_IMPLEMENTATION_REPORT.md.
    // Dispatch's release workflow (DispatchService::releaseDispatch()) is the
    // sole remaining path that creates 'deploy' InventoryTransaction rows; it
    // does not call any of the removed methods.

    // 2026-09-27 — POST /api/inventory-stock/{id}/adjust was removed. No page
    // used it; manual stock adjustment has one path only:
    // POST /api/items/{item}/adjust-stock (ItemController::adjustStock()),
    // which runs the same InventoryAdjustmentService and always records a
    // reason and an inventory transaction.

}
