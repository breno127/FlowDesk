<?php

namespace App\Models;

use App\Enums\SlaTimerStatus;
use App\Enums\SlaTimerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSlaTimer extends Model
{
    protected $fillable = [
        'ticket_id', 'type', 'sla_policy_id', 'business_hour_schedule_id', 'target_seconds', 'warning_percent',
        'status', 'elapsed_seconds', 'started_at', 'segment_started_at', 'paused_at', 'pause_reason',
        'warning_due_at', 'due_at', 'warned_at', 'breached_at', 'completed_at',
    ];

    protected $casts = [
        'type' => SlaTimerType::class,
        'status' => SlaTimerStatus::class,
        'target_seconds' => 'integer',
        'warning_percent' => 'integer',
        'elapsed_seconds' => 'integer',
        'started_at' => 'datetime',
        'segment_started_at' => 'datetime',
        'paused_at' => 'datetime',
        'warning_due_at' => 'datetime',
        'due_at' => 'datetime',
        'warned_at' => 'datetime',
        'breached_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessHourSchedule::class, 'business_hour_schedule_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [SlaTimerStatus::Running->value, SlaTimerStatus::Paused->value]);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', SlaTimerStatus::Running->value);
    }

    public function isBreached(): bool
    {
        return $this->breached_at !== null;
    }
}
