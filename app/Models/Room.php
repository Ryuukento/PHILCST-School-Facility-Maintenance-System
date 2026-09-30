<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    use HasFactory;

    protected $table = 'rooms';

    protected $fillable = [
        'building_id',
        'floor_id',
        'name',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    /**
     * TASK 45 (Damage Report Role Redesign) — the Damage Report view needs to
     * show which Building a damaged asset's room sits in. `rooms.building_id`
     * has always existed and is already populated; the only thing missing was
     * an Eloquent path to read it, so callers previously had no way to resolve
     * the building without a hand-written join.
     *
     * This is purely additive: it introduces no schema change and alters no
     * existing behaviour, because a relationship method is inert until a
     * caller eager-loads or accesses it. It intentionally mirrors the existing
     * PreventiveMaintenanceTask::building() definition rather than inventing a
     * second convention for the same association.
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id', 'id');
    }

    /**
     * Same rationale as building() above; `rooms.floor_id` is likewise already
     * present and populated. Mirrors PreventiveMaintenanceTask::floor().
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class, 'floor_id', 'id');
    }
}
