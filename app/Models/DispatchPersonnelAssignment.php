<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispatchPersonnelAssignment extends Model
{
    use HasFactory;

    protected $table = 'dispatch_personnel_assignments';

    protected $fillable = [
        'dispatch_id',
        'personnel_user_id',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    // Primary/Additional hierarchy removal — the frontend now renders a flat
    // roster straight from Dispatch::personnelAssignments, so each row needs
    // its own name-ready fields rather than making the caller walk the
    // nested personnel/assignedByUser relations itself. Matches the
    // *_name-accessor convention already used on Dispatch.
    protected $appends = ['personnel_name', 'assigned_by_name'];

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class, 'dispatch_id', 'id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'personnel_user_id', 'user_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by', 'user_id');
    }

    // -----------------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------------

    public function getPersonnelNameAttribute(): ?string
    {
        return $this->personnel?->full_name;
    }

    public function getAssignedByNameAttribute(): ?string
    {
        return $this->assignedByUser?->full_name;
    }
}
