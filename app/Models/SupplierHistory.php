<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierHistory extends Model
{
    use HasFactory;

    protected $table = 'supplier_histories';

    protected $fillable = [
        'supplier_id',
        'action',
        'details',
        'performed_by',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }
}
