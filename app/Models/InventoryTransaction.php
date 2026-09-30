<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    use HasFactory;

    protected $table = 'inventory_transactions';

    protected $fillable = [
        'item_id',
        'report_id',
        'dispatch_id',
        'room_id',
        'transaction_type',
        'quantity',
        'reference_note',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }

    // SPRINT 3 — per SPRINT_2_RELATIONSHIP_ARCHITECTURE_REVIEW.md §4.4/§5.
    // report() formalizes the FK that already existed on this table (used
    // today only by the Need Change path); dispatch() is the new FK that
    // closes the "no way to trace a dispatch's own transactions" gap.
    public function report(): BelongsTo
    {
        return $this->belongsTo(MaintenanceReport::class, 'report_id', 'report_id');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class, 'dispatch_id', 'id');
    }
}
