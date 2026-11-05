<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TeamMembership extends Pivot
{
    protected $table = 'team_user';

    public $incrementing = true;

    protected $fillable = ['team_id', 'user_id', 'is_lead', 'capacity', 'is_available', 'last_assigned_at'];

    protected $casts = [
        'is_lead' => 'boolean',
        'capacity' => 'decimal:2',
        'is_available' => 'boolean',
        'last_assigned_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
