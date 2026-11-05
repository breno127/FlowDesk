<?php

namespace App\Models;

use App\Enums\RoutingStrategy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'department_id', 'parent_id', 'escalation_team_id', 'name', 'slug', 'tier',
        'routing_strategy', 'max_weight_per_agent', 'is_active',
    ];

    protected $casts = [
        'tier' => 'integer',
        'routing_strategy' => RoutingStrategy::class,
        'max_weight_per_agent' => 'integer',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function escalationTeam(): BelongsTo
    {
        return $this->belongsTo(self::class, 'escalation_team_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(TeamMembership::class)
            ->withPivot(['id', 'is_lead', 'capacity', 'is_available', 'last_assigned_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
