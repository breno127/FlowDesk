<?php

namespace App\Models;

use App\Enums\SlaTimerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaTarget extends Model
{
    protected $fillable = ['sla_policy_id', 'ticket_priority_id', 'first_response_minutes', 'resolution_minutes', 'warning_percent'];

    protected $casts = [
        'first_response_minutes' => 'integer',
        'resolution_minutes' => 'integer',
        'warning_percent' => 'integer',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'ticket_priority_id');
    }

    public function minutesFor(SlaTimerType $type): ?int
    {
        return $this->{$type->targetColumn()};
    }

    public function effectiveWarningPercent(): int
    {
        return $this->warning_percent ?? (int) config('helpdesk.sla.default_warning_percent');
    }
}
