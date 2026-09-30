<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryCategoryController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    /**
     * GET /api/inventory-categories
     *
     * Query params:
     *   q                – optional search (matches category name or code)
     *   per_page         – 1–200, default 100
     *   page             – default 1
     *   include_inactive – when truthy, also returns inactive categories (default: active-only,
     *                      preserved for Browse-by-Category and item-form dropdown consumers)
     *
     * Response shape:
     *   { success, message, data: { categories: [{id, name, code, department_id, department_name,
     *     default_low_stock_threshold, allow_threshold_override, is_active, sort_order, total_items,
     *     total_stock_quantity}], pagination } }
     */
    public function index(Request $request): JsonResponse
    {
        $q               = trim((string) $request->query('q', ''));
        $perPage         = max(1, min(200, (int) $request->query('per_page', 100)));
        $page            = max(1, (int) $request->query('page', 1));
        $includeInactive = filter_var($request->query('include_inactive', false), FILTER_VALIDATE_BOOLEAN);

        $baseQuery = fn () => DB::table('inventory_categories as c')
            ->when(!$includeInactive, fn ($q2) => $q2->where('c.is_active', 1))
            ->when($q !== '', function ($q2) use ($q) {
                $like = '%' . strtolower($q) . '%';
                $q2->where(function ($sub) use ($like) {
                    $sub->whereRaw('LOWER(c.name) LIKE ?', [$like])
                        ->orWhereRaw("LOWER(COALESCE(c.code, '')) LIKE ?", [$like]);
                });
            });

        $total = $baseQuery()->count();

        $categories = $baseQuery()
            ->select([
                'c.id',
                'c.name',
                'c.code',
                'c.department_id',
                'd.name as department_name',
                'c.default_low_stock_threshold',
                'c.allow_threshold_override',
                'c.is_active',
                'c.sort_order',
                DB::raw('COUNT(i.id) as total_items'),
                DB::raw("COALESCE(SUM(CASE WHEN i.item_type = 'inventory_stock' THEN i.quantity ELSE 0 END), 0) as total_stock_quantity"),
            ])
            ->leftJoin('items as i', 'i.category_id', '=', 'c.id')
            ->leftJoin('departments as d', 'd.department_id', '=', 'c.department_id')
            ->groupBy(
                'c.id', 'c.name', 'c.code', 'c.department_id', 'd.name',
                'c.default_low_stock_threshold', 'c.allow_threshold_override',
                'c.is_active', 'c.sort_order'
            )
            ->orderBy('c.sort_order')
            ->orderBy('c.name')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->ok('Categories retrieved', [
            'categories' => $categories,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    /**
     * POST /api/inventory-categories
     * Create a new inventory category.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->all();

        $name             = trim((string)($data['name'] ?? ''));
        $codeRaw          = trim((string)($data['code'] ?? ''));
        $code             = $codeRaw !== '' ? strtolower($codeRaw) : null;
        $departmentId     = (array_key_exists('department_id', $data) && $data['department_id'] !== '' && $data['department_id'] !== null)
                                ? (int)$data['department_id'] : null;
        $defaultThreshold = (array_key_exists('default_low_stock_threshold', $data) && $data['default_low_stock_threshold'] !== '')
                                ? (int)$data['default_low_stock_threshold'] : null;
        $allowOverride    = array_key_exists('allow_threshold_override', $data)
                                ? (int)((bool)$data['allow_threshold_override']) : 1;
        $isActive         = array_key_exists('is_active', $data)
                                ? (int)((bool)$data['is_active']) : 1;
        $sortOrder        = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : 0;

        if ($name === '') {
            return $this->fail('Category name is required', 422);
        }
        if ($defaultThreshold !== null && $defaultThreshold < 0) {
            return $this->fail('Default threshold must be zero or greater', 422);
        }
        if ($departmentId !== null && !DB::table('departments')->where('department_id', $departmentId)->exists()) {
            return $this->fail('Selected department does not exist', 422);
        }

        $exists = DB::table('inventory_categories')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();
        if ($exists) {
            return $this->fail('Category with this name already exists', 409);
        }

        // TASK 56 — Inventory Category API Validation Hardening.
        // `code` carries its OWN unique index, independent of `name` (see
        // 2026_04_07_001000_add_inventory_categories_and_thresholds.php:
        // `$table->string('code')->nullable()->unique()`), but only `name`
        // was checked above before the insert below. Two categories with
        // different names and the same code therefore passed every
        // application check and violated the unique index at INSERT time,
        // surfacing as an uncaught UniqueConstraintViolationException — a
        // raw 500 (and, with app.debug on, the literal SQL and constraint
        // name in the response body, per bootstrap/app.php's renderer)
        // instead of the clean 409 this method already returns for a
        // duplicate NAME. Reachable through ordinary UI use: `code` is a
        // live, editable field on the Manage Categories form
        // (categoryCodeInput in public/frontend/pages/inventory.php) with no
        // client-side duplicate check.
        //
        // The null guard is required, not defensive: `code` is NULLABLE and
        // a unique index permits unlimited NULLs, so code-less categories
        // are ordinary and must not conflict with each other. Mirrors the
        // legacy InventoryCategory::existsByCode() fixed under Task 55.
        // $code is already lowercased above; LOWER() on the column side
        // still matters because stored values may predate that
        // normalization.
        if ($code !== null) {
            $codeExists = DB::table('inventory_categories')
                ->whereRaw('LOWER(code) = ?', [$code])
                ->exists();
            if ($codeExists) {
                return $this->fail('Category with this code already exists', 409);
            }
        }

        $id = DB::table('inventory_categories')->insertGetId([
            'name'                        => $name,
            'code'                        => $code,
            'department_id'               => $departmentId,
            'default_low_stock_threshold' => $defaultThreshold,
            'allow_threshold_override'    => $allowOverride,
            'is_active'                   => $isActive,
            'sort_order'                  => $sortOrder,
        ]);

        return $this->ok('Category created successfully', ['id' => $id], 201);
    }

    /**
     * PATCH /api/inventory-categories/{id}
     * Update an existing inventory category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->all();

        $name             = trim((string)($data['name'] ?? ''));
        $codeRaw          = trim((string)($data['code'] ?? ''));
        $code             = $codeRaw !== '' ? strtolower($codeRaw) : null;
        $departmentId     = (array_key_exists('department_id', $data) && $data['department_id'] !== '' && $data['department_id'] !== null)
                                ? (int)$data['department_id'] : null;
        $defaultThreshold = (array_key_exists('default_low_stock_threshold', $data) && $data['default_low_stock_threshold'] !== '')
                                ? (int)$data['default_low_stock_threshold'] : null;
        $allowOverride    = array_key_exists('allow_threshold_override', $data)
                                ? (int)((bool)$data['allow_threshold_override']) : 1;
        $isActive         = array_key_exists('is_active', $data)
                                ? (int)((bool)$data['is_active']) : 1;
        $sortOrder        = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : 0;

        if ($name === '') {
            return $this->fail('Category name is required', 422);
        }
        if ($defaultThreshold !== null && $defaultThreshold < 0) {
            return $this->fail('Default threshold must be zero or greater', 422);
        }
        if ($departmentId !== null && !DB::table('departments')->where('department_id', $departmentId)->exists()) {
            return $this->fail('Selected department does not exist', 422);
        }

        if (!DB::table('inventory_categories')->where('id', $id)->exists()) {
            return $this->fail('Category not found', 404);
        }

        $nameConflict = DB::table('inventory_categories')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->where('id', '!=', $id)
            ->exists();
        if ($nameConflict) {
            return $this->fail('Category with this name already exists', 409);
        }

        // TASK 56 — same unguarded unique index as store() above, on the
        // PATCH path. The `id != ?` self-exclusion mirrors the name check
        // immediately above and is what keeps an ordinary edit working: the
        // Manage Categories form resubmits every field on every save
        // (saveCategory() in public/frontend/pages/inventory.php), so the
        // category's own existing code is always present in the payload.
        if ($code !== null) {
            $codeConflict = DB::table('inventory_categories')
                ->whereRaw('LOWER(code) = ?', [$code])
                ->where('id', '!=', $id)
                ->exists();
            if ($codeConflict) {
                return $this->fail('Category with this code already exists', 409);
            }
        }

        DB::table('inventory_categories')->where('id', $id)->update([
            'name'                        => $name,
            'code'                        => $code,
            'department_id'               => $departmentId,
            'default_low_stock_threshold' => $defaultThreshold,
            'allow_threshold_override'    => $allowOverride,
            'is_active'                   => $isActive,
            'sort_order'                  => $sortOrder,
        ]);

        return $this->ok('Category updated successfully');
    }

    /**
     * DELETE /api/inventory-categories/{id}
     * Delete an inventory category (only if it has no items).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (DB::table('items')->where('category_id', $id)->exists()) {
            return $this->fail('Cannot delete a category that has items', 409);
        }

        $category = DB::table('inventory_categories')->where('id', $id)->first();

        $deleted = DB::table('inventory_categories')->where('id', $id)->delete();

        if (!$deleted) {
            return $this->fail('Category not found', 404);
        }

        $this->activityLogService->log([
            'action' => 'DELETE_INVENTORY_CATEGORY',
            'module' => 'inventory',
            'entity_type' => 'category',
            'entity_id' => $id,
            'details' => 'Deleted inventory category ' . ($category->name ?? ('#' . $id)) . '.',
        ], $request);

        return $this->ok('Category deleted successfully');
    }
}
