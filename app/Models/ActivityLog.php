<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'user_role',
        'action',
        'module',
        'entity_type',
        'entity_id',
        'details',
        'ip_address',
        'user_agent',
        'meta_json',
        'dedupe_key',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
