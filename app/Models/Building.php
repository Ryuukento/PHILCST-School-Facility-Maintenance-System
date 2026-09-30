<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Existing building CRUD (BuildingController) reads/writes this table via
// the raw query builder (DB::table('buildings')) and continues to do so
// unchanged. This lightweight Eloquent model is added purely so
// PreventiveMaintenanceTask can declare a belongsTo('building') relationship
// and resolve building_name the same way Dispatch/Room already resolve their
// related names — it does not replace or duplicate BuildingController's
// existing logic.
class Building extends Model
{
    use HasFactory;

    protected $table = 'buildings';

    protected $fillable = [
        'name',
        'description',
    ];
}
