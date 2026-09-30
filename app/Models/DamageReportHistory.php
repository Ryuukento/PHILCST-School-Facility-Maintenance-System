<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamageReportHistory extends Model
{
    use HasFactory;

    protected $table = 'damage_report_histories';

    public $timestamps = false;

    protected $fillable = [
        'damage_report_id',
        'action_type',
        'from_status',
        'to_status',
        'notes',
        'meta_json',
        'changed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function damageReport(): BelongsTo
    {
        return $this->belongsTo(DamageReport::class, 'damage_report_id', 'id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
