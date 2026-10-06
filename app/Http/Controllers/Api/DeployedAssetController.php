<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssetRegistryService;
use App\Support\ApiResponder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/deployed-assets — read-only lookup of tracked units for the
 * "Report Against a Specific Asset" picker and the dispatch detail page.
 *
 * Returns deployed_assets rows and, with include_legacy=1, the legacy
 * `room_asset` items as well, in one list, so the picker can offer both
 * without the browser merging two sources. Each row carries a `source`
 * ('deployed' | 'legacy') and an `id` key unique across both.
 *
 * Read-only: there is no write endpoint for deployed assets. They are created
 * only by a dispatch release, and their codes are immutable.
 */
class DeployedAssetController extends Controller
{
    use ApiResponder;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AssetRegistryService $assetRegistryService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = (array) $request->session()->get('auth_user', $request->session()->get('user', []));
        if (!$this->assetRegistryService->canAccess($authUser)) {
            return $this->fail('Forbidden', 403);
        }

        $roomId = (int) $request->query('room_id', 0);
        $buildingId = (int) $request->query('building_id', 0);
        $dispatchId = (int) $request->query('dispatch_id', 0);
        $itemId = (int) $request->query('item_id', 0);
        $status = (string) $request->query('status', 'active');
        $keyword = trim((string) $request->query('q', (string) $request->query('search', '')));
        $includeLegacy = $request->boolean('include_legacy') && $dispatchId === 0;
        // One dispatch can register up to MAX_TRACKED_UNITS_PER_RELEASE units,
        // and its label sheet needs every one of them in a single response.
        $maxPerPage = $dispatchId > 0 ? AssetRegistryService::MAX_TRACKED_UNITS_PER_RELEASE : self::MAX_PER_PAGE;
        $perPage = max(1, min($maxPerPage, (int) $request->query('per_page', 50)));

        $deployed = DB::table('deployed_assets as da')
            ->join('items as i', 'da.item_id', '=', 'i.id')
            ->leftJoin('rooms as r', 'da.room_id', '=', 'r.id')
            ->leftJoin('floors as f', 'r.floor_id', '=', 'f.id')
            ->leftJoin('buildings as b', 'r.building_id', '=', 'b.id')
            ->leftJoin('dispatches as d', 'da.dispatch_id', '=', 'd.id')
            ->select([
                'da.id', 'da.asset_code', 'da.code_source', 'da.status', 'da.deployed_at',
                'da.item_id', 'i.name as item_name', 'i.brand', 'i.model',
                'da.room_id', 'r.name as room_name', 'f.name as floor_name',
                'b.id as building_id', 'b.name as building_name',
                'da.dispatch_id', 'd.dispatch_code', 'da.dispatch_item_id',
            ]);

        if ($status !== 'all') {
            $deployed->where('da.status', in_array($status, ['active', 'returned', 'disposed'], true) ? $status : 'active');
        }
        if ($roomId > 0) {
            $deployed->where('da.room_id', $roomId);
        }
        if ($buildingId > 0) {
            $deployed->where('r.building_id', $buildingId);
        }
        if ($dispatchId > 0) {
            $deployed->where('da.dispatch_id', $dispatchId);
        }
        if ($itemId > 0) {
            $deployed->where('da.item_id', $itemId);
        }
        $this->applyKeyword($deployed, $keyword, 'da.asset_code', 'i');

        // Within one dispatch, list units in the order they were registered at
        // release (Asset 1, Asset 2, ...), per line — that is the order the
        // printed labels number them "Unit 1 of N". Elsewhere, by code.
        if ($dispatchId > 0) {
            $deployed->orderBy('da.dispatch_item_id')->orderBy('da.id');
        } else {
            $deployed->orderBy('da.asset_code');
        }

        $rows = $deployed->limit($perPage)->get()
            ->map(fn ($row) => $this->presentDeployed($row));

        if ($includeLegacy && $rows->count() < $perPage) {
            $rows = $rows->concat($this->legacyRows($roomId, $buildingId, $itemId, $keyword, $perPage - $rows->count()));
        }

        return $this->ok('Deployed assets retrieved', ['items' => $rows->values()]);
    }

    /**
     * Legacy `room_asset` items (one unit installed in a room, entered before
     * Dispatch existed). Unchanged and still reportable; listed here only so
     * the picker can show both kinds of asset together.
     */
    private function legacyRows(int $roomId, int $buildingId, int $itemId, string $keyword, int $limit)
    {
        $legacy = DB::table('items as i')
            ->join('rooms as r', 'i.room_id', '=', 'r.id')
            ->leftJoin('floors as f', 'r.floor_id', '=', 'f.id')
            ->leftJoin('buildings as b', 'r.building_id', '=', 'b.id')
            ->where('i.item_type', 'room_asset')
            ->select([
                'i.id', 'i.asset_code', 'i.name as item_name', 'i.brand', 'i.model',
                'i.room_id', 'r.name as room_name', 'f.name as floor_name',
                'b.id as building_id', 'b.name as building_name', 'i.created_at',
            ]);

        if ($roomId > 0) {
            $legacy->where('i.room_id', $roomId);
        }
        if ($buildingId > 0) {
            $legacy->where('r.building_id', $buildingId);
        }
        if ($itemId > 0) {
            $legacy->where('i.id', $itemId);
        }
        $this->applyKeyword($legacy, $keyword, 'i.asset_code', 'i');

        return $legacy->orderBy('i.name')->limit($limit)->get()
            ->map(fn ($row) => $this->presentLegacy($row));
    }

    /**
     * Search by asset code, equipment name/brand/model, room or building.
     */
    private function applyKeyword(Builder $query, string $keyword, string $codeColumn, string $itemAlias): void
    {
        if ($keyword === '') {
            return;
        }

        $like = '%' . addcslashes($keyword, '\\%_') . '%';
        $query->where(function (Builder $b) use ($like, $codeColumn, $itemAlias): void {
            $b->where($codeColumn, 'like', $like)
                ->orWhere($itemAlias . '.name', 'like', $like)
                ->orWhere($itemAlias . '.brand', 'like', $like)
                ->orWhere($itemAlias . '.model', 'like', $like)
                ->orWhere('r.name', 'like', $like)
                ->orWhere('b.name', 'like', $like);
        });
    }

    private function presentDeployed(object $row): array
    {
        return [
            'id' => 'asset-' . $row->id,
            'source' => 'deployed',
            'deployed_asset_id' => (int) $row->id,
            'asset_code' => $row->asset_code,
            'code_source' => $row->code_source,
            'item_id' => (int) $row->item_id,
            'item_name' => $row->item_name,
            'brand' => $row->brand,
            'model' => $row->model,
            'room_id' => $row->room_id !== null ? (int) $row->room_id : null,
            'room_name' => $row->room_name,
            'floor_name' => $row->floor_name,
            'building_id' => $row->building_id !== null ? (int) $row->building_id : null,
            'building_name' => $row->building_name,
            'dispatch_id' => $row->dispatch_id !== null ? (int) $row->dispatch_id : null,
            'dispatch_code' => $row->dispatch_code,
            'dispatch_item_id' => $row->dispatch_item_id !== null ? (int) $row->dispatch_item_id : null,
            'status' => $row->status,
            'deployed_at' => $row->deployed_at,
            'name' => $this->label($row),
        ];
    }

    private function presentLegacy(object $row): array
    {
        return [
            'id' => 'item-' . $row->id,
            'source' => 'legacy',
            'deployed_asset_id' => null,
            'asset_code' => $row->asset_code,
            'code_source' => null,
            'item_id' => (int) $row->id,
            'item_name' => $row->item_name,
            'brand' => $row->brand,
            'model' => $row->model,
            'room_id' => (int) $row->room_id,
            'room_name' => $row->room_name,
            'floor_name' => $row->floor_name,
            'building_id' => $row->building_id !== null ? (int) $row->building_id : null,
            'building_name' => $row->building_name,
            'dispatch_id' => null,
            'dispatch_code' => null,
            'dispatch_item_id' => null,
            'status' => 'active',
            'deployed_at' => $row->created_at,
            'name' => $this->label($row),
        ];
    }

    /** "Monoblock Chair · Room 101, Main Building" */
    private function label(object $row): string
    {
        $where = implode(', ', array_filter([$row->room_name ?? null, $row->building_name ?? null]));

        return trim(($row->item_name ?? 'Equipment') . ($where !== '' ? ' · ' . $where : ''));
    }
}
