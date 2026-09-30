<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';

    protected $fillable = [
        'name',
        'contact_person',
        'contact_email',
        'phone',
        'address',
        'notes',
        'status',
    ];

    public function histories(): HasMany
    {
        return $this->hasMany(SupplierHistory::class, 'supplier_id', 'id');
    }
}
