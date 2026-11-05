<?php

namespace App\Models;

use App\Enums\ColumnCategory;
use App\Enums\SlaBehavior;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardColumn extends Model
{
    protected $fillable = ['board_id', 'name', 'color', 'position', 'category', 'sla_behavior', 'wip_limit', 'is_initial'];

    protected $casts = [
        'position' => 'integer',
        'category' => ColumnCategory::class,
        'sla_behavior' => SlaBehavior::class,
        'wip_limit' => 'integer',
        'is_initial' => 'boolean',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('position');
    }

    public function isFinal(): bool
    {
        return $this->category->isFinal();
    }
}
