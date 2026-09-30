<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// See Building.php for why this lightweight model exists alongside
// BuildingController's existing raw-query-builder access to the same table.
class Floor extends Model
{
    use HasFactory;

    protected $table = 'floors';

    protected $fillable = [
        'building_id',
        'name',
        'description',
    ];
}
