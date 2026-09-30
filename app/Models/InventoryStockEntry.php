<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockEntry extends Model
{
    use HasFactory;

    protected $table = 'inventory_stock_entries';

    protected $fillable = [
        'stock_entry_id',
        'or_number',
        'supplier_name',
        'date_received',
        'item_id',
        'category_id',
        'department_id',
        'room_id',
        'receiver_user_id',
        'item_name',
        'quantity',
        'unit_type',
        'description',
        'item_condition',
    ];

    protected function casts(): array
    {
        return [
            'date_received' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_user_id', 'user_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }
}
