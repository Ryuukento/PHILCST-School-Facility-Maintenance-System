<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\RoleNormalizerService;
use App\Support\ApiResponder;
use App\Support\DeployedItemsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuildingController extends Controller
{
    use ApiResponder;

    public function __construct(
        // TASK 18 — "Building Updated" notification.
        private readonly NotificationService $notificationService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    /**
     * GET /api/buildings
     *
     * Query params:
     *   q        – optional search (matches building name)
     *   per_page – 1–200, default 100
     *   page     – default 1
     *
     * Response shape:
     *   { success, message, data: { buildings: [{id, name, description, floor_count, room_count}], pagination } }
     *
     * room_count only counts active rooms (r.is_active = 1 is applied in the
     * JOIN's ON clause, not a WHERE, so buildings with zero active rooms
     * still appear with room_count = 0 instead of being dropped). Rooms are
     * soft-deactivated rather than hard-deleted (see RoomController), so
     * without this the count would include rooms no longer meant to surface
     * in normal building/room-picker UIs.
     */
    public function index(Request $request): JsonResponse
    {
        $q       = trim((string) $request->query('q', ''));
        $perPage = max(1, min(200, (int) $request->query('per_page', 100)));
        $page    = max(1, (int) $request->query('page', 1));

        $baseQuery = fn () => DB::table('buildings as b')
            ->when($q !== '', fn ($q2) => $q2->whereRaw('LOWER(b.name) LIKE ?', ['%' . strtolower($q) . '%']));

        $total = $baseQuery()->count();

        $buildings = $baseQuery()
            ->select([
                'b.id',
                'b.name',
                'b.description',
                DB::raw('COUNT(DISTINCT f.id) as floor_count'),
                DB::raw('COUNT(DISTINCT r.id) as room_count'),
            ])
            ->leftJoin('floors as f', 'b.id', '=', 'f.building_id')
            ->leftJoin('rooms as r', function ($join) {
                $join->on('b.id', '=', 'r.building_id')
                     ->where('r.is_active', 1);
            })
            ->groupBy('b.id', 'b.name', 'b.description')
            ->orderByDesc('b.created_at')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->ok('Buildings retrieved', [
            'buildings'  => $buildings,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    /**
     * GET /api/buildings/{id}/floors
     *
     * Response shape:
     *   { success, message, data: { floors: [{id, name, description, building_id, room_count, item_count}] } }
     *
     * room_count/item_count only reflect active rooms — see the is_active
     * note on index() above. The rooms join filters on r.is_active in its ON
     * clause (not a WHERE) so floors with zero active rooms still appear
     * with room_count = 0 rather than being dropped by the LEFT JOIN.
     *
     * item_count is summed via DeployedItemsQuery::perRoom() — the same
     * released-dispatch + direct-room_asset union deployedItems() below
     * uses — NOT a raw join on items.room_id. That column is only ever set
     * for direct room_asset entries, so a plain join on it was blind to
     * every item that arrived via the normal Dispatch/Approve/Release
     * workflow, which is why this used to read 0 for rooms that clearly had
     * released dispatches (see Buildings Overview bug report).
     */
    public function floors(int $id): JsonResponse
    {
        $floors = DB::table('floors as f')
            ->select([
                'f.id',
                'f.name',
                'f.description',
                'f.building_id',
                DB::raw('COALESCE(COUNT(DISTINCT r.id), 0) as room_count'),
                DB::raw('COALESCE(SUM(deployed.quantity), 0) as item_count'),
            ])
            ->leftJoin('rooms as r', function ($join) {
                $join->on('f.id', '=', 'r.floor_id')
                     ->where('r.is_active', 1);
            })
            ->leftJoinSub(DeployedItemsQuery::perRoom(), 'deployed', 'deployed.room_id', '=', 'r.id')
            ->where('f.building_id', $id)
            ->groupBy('f.id', 'f.name', 'f.description', 'f.building_id')
            ->orderBy('f.name')
            ->get();

        return $this->ok('Floors retrieved', [
            'floors' => $floors,
        ]);
    }

    /**
     * GET /api/buildings/deployed-items
     *
     * Two sources are unioned so every item physically sitting in a room
     * shows up here, not just ones with a dispatch trail:
     *  - "dispatch" branch: released dispatch_items (has a dispatch_code).
     *  - "direct" branch: items with item_type='room_asset' — legacy/
     *    pre-system equipment entered via the Inventory "Add Item" modal's
     *    "Already Deployed In Room" selector, which never went through
     *    Dispatch/Approve/Release (dispatch_code is NULL for these rows;
     *    the frontend already renders that as blank, same as
     *    DeploymentTrackingController's 'direct' rows).
     * Without the direct branch, both the per-building "Items" count on
     * Buildings Overview and this print-report endpoint silently excluded
     * every legacy-deployed item, even though DeploymentTrackingController
     * (a separate screen) already accounted for them.
     */
    public function deployedItems(Request $request): JsonResponse
    {
        $buildingId = (int) $request->query('building_id', 0);
        $roomId = (int) $request->query('room_id', 0);

        $dispatchQuery = DB::table('dispatches as d')
            ->select([
                'i.name as item_name',
                DB::raw('COALESCE(c.name, \'Uncategorized\') as category'),
                'di.quantity',
                'i.unit_type as unit',
                'i.item_condition as condition',
                // INVENTORY REPORTS PER ROOM FIX — asset_code/status were never
                // selected here because this endpoint originally only fed the
                // Buildings Overview cards, which don't show them. The Per Room
                // Report table (inventory-reports.php) now reuses this same
                // union as its item source (see that controller's docblock
                // above), and its existing table columns expect both.
                'i.asset_code',
                'i.status',
                'd.updated_at as date_dispatched',
                'd.dispatch_code',
                'r.name as room_name',
                'b.name as building_name',
                'b.id as building_id',
                'r.id as room_id',
            ])
            ->join('dispatch_items as di', 'd.id', '=', 'di.dispatch_id')
            ->join('items as i', 'di.item_id', '=', 'i.id')
            ->leftJoin('rooms as r', 'd.room_id', '=', 'r.id')
            ->leftJoin('buildings as b', 'r.building_id', '=', 'b.id')
            ->leftJoin('inventory_categories as c', 'i.category_id', '=', 'c.id')
            ->where('d.status', 'released');

        $directQuery = DB::table('items as ri')
            ->select([
                'ri.name as item_name',
                DB::raw('COALESCE(c2.name, \'Uncategorized\') as category'),
                'ri.quantity',
                'ri.unit_type as unit',
                'ri.item_condition as condition',
                'ri.asset_code',
                'ri.status',
                'ri.created_at as date_dispatched',
                DB::raw('NULL as dispatch_code'),
                'r2.name as room_name',
                'b2.name as building_name',
                'b2.id as building_id',
                'r2.id as room_id',
            ])
            ->join('rooms as r2', 'ri.room_id', '=', 'r2.id')
            ->leftJoin('buildings as b2', 'r2.building_id', '=', 'b2.id')
            ->leftJoin('inventory_categories as c2', 'ri.category_id', '=', 'c2.id')
            ->where('ri.item_type', 'room_asset');

        if ($buildingId > 0) {
            $dispatchQuery->where('b.id', $buildingId);
            $directQuery->where('b2.id', $buildingId);
        }

        if ($roomId > 0) {
            $dispatchQuery->where('r.id', $roomId);
            $directQuery->where('r2.id', $roomId);
        }

        $items = $dispatchQuery
            ->unionAll($directQuery)
            ->get()
            ->sortBy([
                ['building_name', 'asc'],
                ['room_name', 'asc'],
                ['date_dispatched', 'asc'],
                ['item_name', 'asc'],
            ])
            ->values();

        return $this->ok('Deployed items retrieved', ['items' => $items]);
    }

    /** POST /api/buildings */
    public function store(Request $request): JsonResponse
    {
        $name        = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));

        if ($name === '') {
            return $this->fail('Building name is required', 422);
        }

        $exists = DB::table('buildings')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();
        if ($exists) {
            return $this->fail('Building with this name already exists', 409);
        }

        $id = DB::table('buildings')->insertGetId([
            'name'        => $name,
            'description' => $description,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $this->ok('Building created successfully', ['id' => $id, 'name' => $name], 201);
    }

    /** PATCH /api/buildings/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $name        = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));

        if ($name === '') {
            return $this->fail('Building name is required', 422);
        }

        $building = DB::table('buildings')->where('id', $id)->first();
        if (!$building) {
            return $this->fail('Building not found', 404);
        }

        $duplicate = DB::table('buildings')
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->where('id', '!=', $id)
            ->exists();
        if ($duplicate) {
            return $this->fail('Building with this name already exists', 409);
        }

        DB::table('buildings')->where('id', $id)->update([
            'name'        => $name,
            'description' => $description,
            'updated_at'  => now(),
        ]);

        // TASK 18 — "Building Updated" notification.
        // TASK 52 — only notify on a genuine change of name/description.
        // Re-saving the same values (e.g. an unchanged form submit) should
        // not spam every Super Admin/Head Maintenance user, mirroring the
        // "only notify on a genuine change" pattern already established in
        // DispatchService::assignReleasePersonnel().
        if ($building->name !== $name || (string) $building->description !== $description) {
            $this->notifyBuildingUpdated($request, $id, $name);

            $this->activityLogService->log([
                'action' => 'UPDATE_BUILDING',
                'module' => 'buildings',
                'entity_type' => 'building',
                'entity_id' => $id,
                'details' => 'Updated building ' . $name . '.',
            ], $request);
        }

        return $this->ok('Building updated successfully');
    }

    /**
     * TASK 18 — notifies active Super Admins and Head Maintenance (maintenance_admin)
     * users that a building's details were updated. Excludes the acting user
     * to avoid self-notification. entity_type is 'building' — there is no
     * building detail page, so the frontend ENTITY_ROUTES entry for
     * 'building' is clientOnly and navigates to buildings-overview.php with
     * a highlight, mirroring the existing 'user' entity route.
     */
    private function notifyBuildingUpdated(Request $request, int $buildingId, string $buildingName): void
    {
        $performedBy = (int) $request->session()->get('user_id');

        $title = 'Building Updated: ' . $buildingName;
        $message = 'Building "' . $buildingName . '" details were updated.';

        $recipientRoles = RoleNormalizerService::rawValuesFor(['super_admin', 'maintenance_admin']);
        $recipientIds = User::query()
            ->where('status', 'active')
            ->whereIn('role', $recipientRoles)
            ->when($performedBy, fn ($query) => $query->where('user_id', '!=', $performedBy))
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            $this->notificationService->notify((int) $recipientId, $title, $message, 'building', $buildingId);
        }
    }

    /** DELETE /api/buildings/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (DB::table('floors')->where('building_id', $id)->exists()) {
            return $this->fail('Cannot delete building with existing floors', 409);
        }
        if (DB::table('rooms')->where('building_id', $id)->exists()) {
            return $this->fail('Cannot delete building with existing rooms', 409);
        }

        $building = DB::table('buildings')->where('id', $id)->first();

        $deleted = DB::table('buildings')->where('id', $id)->delete();
        if (!$deleted) {
            return $this->fail('Building not found', 404);
        }

        $this->activityLogService->log([
            'action' => 'DELETE_BUILDING',
            'module' => 'buildings',
            'entity_type' => 'building',
            'entity_id' => $id,
            'details' => 'Deleted building ' . ($building->name ?? ('#' . $id)) . '.',
        ], $request);

        return $this->ok('Building deleted successfully');
    }

    /** POST /api/buildings/{id}/floors */
    public function storeFloor(Request $request, int $id): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->fail('Floor name is required', 422);
        }

        if (!DB::table('buildings')->where('id', $id)->exists()) {
            return $this->fail('Building not found', 404);
        }

        $duplicate = DB::table('floors')
            ->where('building_id', $id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();
        if ($duplicate) {
            return $this->fail('Floor with this name already exists in this building', 409);
        }

        $floorId = DB::table('floors')->insertGetId([
            'building_id' => $id,
            'name'        => $name,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $this->ok('Floor created successfully', ['id' => $floorId, 'name' => $name], 201);
    }

    /** DELETE /api/buildings/{id}/floors/{floorId} */
    public function destroyFloor(Request $request, int $id, int $floorId): JsonResponse
    {
        if (DB::table('rooms')->where('floor_id', $floorId)->exists()) {
            return $this->fail('Cannot delete floor with existing rooms', 409);
        }

        $floor = DB::table('floors')->where('id', $floorId)->where('building_id', $id)->first();

        $deleted = DB::table('floors')
            ->where('id', $floorId)
            ->where('building_id', $id)
            ->delete();
        if (!$deleted) {
            return $this->fail('Floor not found', 404);
        }

        $this->activityLogService->log([
            'action' => 'DELETE_FLOOR',
            'module' => 'buildings',
            'entity_type' => 'floor',
            'entity_id' => $floorId,
            'details' => 'Deleted floor ' . ($floor->name ?? ('#' . $floorId)) . '.',
        ], $request);

        return $this->ok('Floor deleted successfully');
    }
}
