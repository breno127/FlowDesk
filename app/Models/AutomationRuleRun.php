<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRuleRun extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['automation_rule_id', 'ticket_id', 'event', 'status', 'executed_actions', 'error'];

    protected $casts = ['executed_actions' => 'array'];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('helpdesk.automation.retention_days', 90)));
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
