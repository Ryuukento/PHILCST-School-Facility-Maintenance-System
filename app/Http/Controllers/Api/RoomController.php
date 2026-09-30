<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Support\ApiResponder;
use App\Support\DeployedItemsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
    }

    /**
     * GET /api/rooms
     *
     * Query params:
     *   q           – optional search (matches room name or building name)
     *   per_page    – 1–200, default 50
     *   page        – default 1
     *   building_id – optional filter by building
     *   with_item_counts – optional "1"/"true"; adds item_count + total_quantity
     *                      per room via a grouped LEFT JOIN against
     *                      DeployedItemsQuery::perRoom() (released dispatch
     *                      items + direct room_asset items — NOT a raw join
     *                      on items.room_id, which is only ever set for the
     *                      latter and is blind to the normal Dispatch/
     *                      Approve/Release path; see that class for why).
     *                      Opt-in on purpose: /api/rooms is consumed by several
     *                      pages that do not need the aggregate, so they must not
     *                      pay for the join.
     *   include_inactive – when truthy, also returns inactive (deactivated/sample)
     *                      rooms (default: active-only). Same convention as
     *                      InventoryCategoryController. Deactivated rooms are kept
     *                      (never hard-deleted) so historical dispatches/reports/PM
     *                      tasks referencing them still resolve; this flag just
     *                      controls whether they surface in normal room-picker UIs.
     *
     * Response shape:
     *   { success, message, data: { rooms: [{id, name, capacity, building_id, building_name, floor_id, floor_name, is_active}], pagination: {...} } }
     *   (+ item_count, total_quantity on each room when with_item_counts is set)
     */
    public function index(Request $request): JsonResponse
    {
        $q               = trim((string) $request->query('q', ''));
        $perPage         = max(1, min(200, (int) $request->query('per_page', 50)));
        $page            = max(1, (int) $request->query('page', 1));
        $buildingId      = (int) $request->query('building_id', 0);
        $floorId         = (int) $request->query('floor_id', 0);
        $withCounts      = filter_var($request->query('with_item_counts', false), FILTER_VALIDATE_BOOLEAN);
        $includeInactive = filter_var($request->query('include_inactive', false), FILTER_VALIDATE_BOOLEAN);

        // Filters are shared by the count query and the page query.
        $applyFilters = function ($query) use ($q, $buildingId, $floorId, $includeInactive) {
            if (!$includeInactive) {
                $query->where('rooms.is_active', 1);
            }

            if ($q !== '') {
                $like = '%' . strtolower($q) . '%';
                $query->where(function ($sub) use ($like) {
                    $sub->whereRaw('LOWER(rooms.name) LIKE ?',       [$like])
                        ->orWhereRaw('LOWER(buildings.name) LIKE ?', [$like]);
                });
            }

            if ($buildingId > 0) {
                $query->where('rooms.building_id', $buildingId);
            }

            if ($floorId > 0) {
                $query->where('rooms.floor_id', $floorId);
            }

            return $query;
        };

        // Total is counted WITHOUT the items join/GROUP BY. Under GROUP BY, ->count()
        // returns the row count of the first group rather than the number of groups,
        // and wrapping the grouped query in a pagination subquery loses the
        // rooms.id reference the aggregate needs.
        $total = (int) $applyFilters(
            DB::table('rooms')
                ->leftJoin('buildings', 'rooms.building_id', '=', 'buildings.id')
                ->leftJoin('floors',    'rooms.floor_id',    '=', 'floors.id')
        )->count();

        $columns = [
            'rooms.id',
            'rooms.name',
            'rooms.capacity',
            'rooms.building_id',
            'rooms.floor_id',
            'rooms.is_active',
            'buildings.name as building_name',
            'floors.name as floor_name',
        ];

        if ($withCounts) {
            $columns[] = DB::raw('COUNT(deployed.quantity) AS item_count');
            $columns[] = DB::raw('COALESCE(SUM(deployed.quantity), 0) AS total_quantity');
        }

        $query = $applyFilters(
            DB::table('rooms')
                ->select($columns)
                ->leftJoin('buildings', 'rooms.building_id', '=', 'buildings.id')
                ->leftJoin('floors',    'rooms.floor_id',    '=', 'floors.id')
        )
            ->orderBy('buildings.name')
            ->orderBy('floors.name')
            ->orderBy('rooms.name');

        if ($withCounts) {
            $query->leftJoinSub(DeployedItemsQuery::perRoom(), 'deployed', 'deployed.room_id', '=', 'rooms.id')
                  ->groupBy(
                      'rooms.id',
                      'rooms.name',
                      'rooms.capacity',
                      'rooms.building_id',
                      'rooms.floor_id',
                      'rooms.is_active',
                      'buildings.name',
                      'floors.name'
                  );
        }

        $rooms = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return $this->ok('Rooms retrieved', [
            'rooms'      => $rooms,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    /** POST /api/rooms */
    public function store(Request $request): JsonResponse
    {
        $buildingId = (int) $request->input('building_id', 0);
        $floorId    = (int) $request->input('floor_id', 0);
        $name       = trim((string) $request->input('name', ''));
        $capacity   = $request->input('capacity');

        if ($buildingId <= 0 || $floorId <= 0 || $name === '') {
            return $this->fail('Building, floor, and room name are required', 422);
        }

        if ($capacity !== null && $capacity !== '') {
            $capacity = (int) $capacity;
            if ($capacity < 1 || $capacity > 60) {
                return $this->fail('Room capacity must be between 1 and 60', 422);
            }
        } else {
            $capacity = null;
        }

        $floor = DB::table('floors')
            ->where('id', $floorId)
            ->where('building_id', $buildingId)
            ->first();
        if (!$floor) {
            return $this->fail('Floor not found or does not belong to the building', 422);
        }

        $duplicate = DB::table('rooms')
            ->where('floor_id', $floorId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();
        if ($duplicate) {
            return $this->fail('Room with this name already exists on this floor', 409);
        }

        $id = DB::table('rooms')->insertGetId([
            'building_id' => $buildingId,
            'floor_id'    => $floorId,
            'name'        => $name,
            'capacity'    => $capacity,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $this->ok('Room created successfully', ['id' => $id, 'name' => $name], 201);
    }

    /** PATCH /api/rooms/{id} — rename a room (floor/building are not reassignable here). */
    public function update(Request $request, int $id): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->fail('Room name is required', 422);
        }

        $room = DB::table('rooms')->where('id', $id)->first();
        if (!$room) {
            return $this->fail('Room not found', 404);
        }

        $duplicate = DB::table('rooms')
            ->where('floor_id', $room->floor_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->where('id', '!=', $id)
            ->exists();
        if ($duplicate) {
            return $this->fail('Room with this name already exists on this floor', 409);
        }

        DB::table('rooms')->where('id', $id)->update([
            'name'       => $name,
            'updated_at' => now(),
        ]);

        return $this->ok('Room updated successfully');
    }

    /** DELETE /api/rooms/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $room = DB::table('rooms')->where('id', $id)->first();

        $deleted = DB::table('rooms')->where('id', $id)->delete();
        if (!$deleted) {
            return $this->fail('Room not found', 404);
        }

        $this->activityLogService->log([
            'action' => 'DELETE_ROOM',
            'module' => 'rooms',
            'entity_type' => 'room',
            'entity_id' => $id,
            'details' => 'Deleted room ' . ($room->name ?? ('#' . $id)) . '.',
        ], $request);

        return $this->ok('Room deleted successfully');
    }
}
