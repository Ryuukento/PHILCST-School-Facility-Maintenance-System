<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared definition of "how many items are actually deployed in a room".
 *
 * There are two independent paths that put an item in a room:
 *  - the Dispatch -> Approve -> Release workflow: once a dispatch's status
 *    is 'released', its dispatch_items rows are considered deployed to
 *    dispatches.room_id (the "Room / Lab" field on Create Dispatch).
 *  - legacy/pre-system equipment entered directly via the Inventory "Add
 *    Item" modal's "Already Deployed In Room" option (items.item_type =
 *    'room_asset', items.room_id set directly on the item row).
 *
 * items.room_id is ONLY ever populated by the second path. Releasing a
 * dispatch never writes back to the item row — one item can be split across
 * many dispatches/rooms over time, so there is no single "the" room to
 * stamp on it; the room lives on the dispatch instead. Any per-room item
 * count that joins on items.room_id alone is therefore blind to every
 * dispatch-released item, which is the normal path (Buildings Overview's
 * "Items" card counted this correctly via BuildingController::deployedItems()'s
 * union; Floors and Rooms did not, which is why they showed 0 for rooms that
 * clearly had released dispatches).
 *
 * This class is the single definition every "items per room" call site
 * should share, so Buildings Overview's building/floor/room levels always
 * agree with each other and with Deployment Tracking.
 */
class DeployedItemsQuery
{
    /**
     * One row per deployed batch: one per released dispatch_items row, plus
     * one per direct room_asset item. Callers LEFT JOIN this (aliased) on
     * room_id and GROUP BY the room to get per-room totals — COUNT(...) for
     * a batch count, SUM(quantity) for total quantity.
     */
    public static function perRoom(): Builder
    {
        $dispatched = DB::table('dispatches as d')
            ->join('dispatch_items as di', 'd.id', '=', 'di.dispatch_id')
            ->where('d.status', 'released')
            ->whereNotNull('d.room_id')
            ->select(['d.room_id as room_id', 'di.quantity as quantity']);

        $direct = DB::table('items')
            ->where('item_type', 'room_asset')
            ->whereNotNull('room_id')
            ->select(['room_id', 'quantity']);

        return $dispatched->unionAll($direct);
    }
}
