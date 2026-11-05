<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    protected $fillable = [
        'name', 'description', 'board_id', 'department_id', 'event', 'match', 'conditions', 'actions',
        'stop_processing', 'sort', 'is_active', 'run_count', 'last_run_at', 'created_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'stop_processing' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'run_count' => 'integer',
        'last_run_at' => 'datetime',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRuleRun::class);
    }

    /** Regras ativas de um evento aplicáveis ao escopo do ticket, em ordem de execução. */
    public function scopeApplicableTo(Builder $query, string $event, Ticket $ticket): Builder
    {
        return $query->where('is_active', true)
            ->where('event', $event)
            ->where(fn (Builder $q) => $q->whereNull('board_id')->orWhere('board_id', $ticket->board_id))
            ->where(fn (Builder $q) => $q->whereNull('department_id')->orWhere('department_id', $ticket->department_id))
            ->orderBy('sort')
            ->orderBy('id');
    }
}
