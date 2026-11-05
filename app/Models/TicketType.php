<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    protected $fillable = ['department_id', 'name', 'slug', 'icon', 'color', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function customFields(): BelongsToMany
    {
        return $this->belongsToMany(CustomField::class)
            ->withPivot(['id', 'is_required', 'visible_to_customer', 'sort', 'column_span'])
            ->withTimestamps()
            ->orderByPivot('sort');
    }

    public function fieldAssignments(): HasMany
    {
        return $this->hasMany(CustomFieldTicketType::class)->orderBy('sort');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
