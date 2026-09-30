<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ApiResponder;

    /**
     * GET /api/departments
     *
     * Query params:
     *   q        – optional search string (matches name, case-insensitive)
     *   per_page – items per page, 1–200, default 20
     *   page     – page number, default 1
     *
     * Response shape (identical to legacy departments.php):
     *   { success, message, data: { departments: [{department_id, name, status}], pagination: {...} } }
     */
    public function index(Request $request): JsonResponse
    {
        $q       = trim((string) $request->query('q', ''));
        $perPage = max(1, min(200, (int) $request->query('per_page', 20)));
        $page    = max(1, (int) $request->query('page', 1));

        $query = Department::query()
            ->select(['department_id', 'name', 'status'])
            ->where('status', 'active')
            ->orderBy('name');

        if ($q !== '') {
            // HIGH_PRIORITY_FIX_4 — the departments table has no `code` column
            // (see database/migrations/2026_03_27_000100_create_departments_table.php);
            // the prior orWhereRaw('code LIKE ...') condition referenced a
            // non-existent column and would throw a SQL error on any ?q= request.
            // No equivalent field exists, so the invalid condition is removed
            // rather than replaced; search now matches on `name` only.
            $like = '%' . strtolower($q) . '%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        $total       = $query->count();
        $departments = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->ok('Departments retrieved', [
            'departments' => $departments,
            'pagination'  => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }
}
