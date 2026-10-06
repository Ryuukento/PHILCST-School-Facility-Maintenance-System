<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One physical unit released through a Dispatch with "Track as Assets"
 * enabled. Created only by AssetRegistryService::registerReleasePlan().
 *
 * asset_code and code_source are immutable once the row exists: an official
 * school code must never be overwritten, and a generated SFMS code is the
 * unit's permanent identity. The updating() guard below enforces that for
 * every Eloquent write path; no API endpoint updates these columns at all.
 */
class DeployedAsset extends Model
{
    public const CODE_SOURCE_EXISTING = 'existing';
    public const CODE_SOURCE_GENERATED = 'generated';

    public const STATUS_ACTIVE = 'active';

    /** Columns that may never change after creation. */
    private const IMMUTABLE_COLUMNS = ['asset_code', 'code_source'];

    protected $table = 'deployed_assets';

    protected $fillable = [
        'asset_code',
        'code_source',
        'item_id',
        'dispatch_id',
        'dispatch_item_id',
        'room_id',
        'status',
        'deployed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'deployed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DeployedAsset $asset): void {
            foreach (self::IMMUTABLE_COLUMNS as $column) {
                if ($asset->isDirty($column)) {
                    throw new LogicException('Asset codes cannot be changed once they are registered.');
                }
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class, 'dispatch_id', 'id');
    }

    public function dispatchItem(): BelongsTo
    {
        return $this->belongsTo(DispatchItem::class, 'dispatch_item_id', 'id');
    }
}
