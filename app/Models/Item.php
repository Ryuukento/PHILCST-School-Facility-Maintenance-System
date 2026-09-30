<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

    protected $table = 'items';

    protected $fillable = [
        'room_id',
        'category_id',
        'brand',
        'model',
        'item_type',
        'name',
        'asset_code',
        'unit_type',
        'item_condition',
        'status',
        'quantity',
        'reserved_quantity',
        'reorder_level',
        'low_stock_threshold_override',
        'description',
    ];
}
