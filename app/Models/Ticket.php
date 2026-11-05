<?php

namespace App\Models;

use App\Enums\MessageVisibility;
use App\Enums\SlaTimerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number', 'subject', 'description', 'board_id', 'board_column_id', 'position', 'ticket_type_id',
        'ticket_priority_id', 'department_id', 'team_id', 'assignee_id', 'contact_id', 'client_company_id',
        'sla_policy_id', 'created_by', 'channel', 'assigned_at', 'first_response_at', 'resolved_at', 'closed_at',
        'last_customer_activity_at', 'last_agent_activity_at', 'csat_score', 'csat_comment', 'csat_answered_at',
        'jira_issue_key', 'meta',
    ];

    protected $casts = [
        'position' => 'integer',
        'assigned_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_customer_activity_at' => 'datetime',
        'last_agent_activity_at' => 'datetime',
        'csat_score' => 'integer',
        'csat_answered_at' => 'datetime',
        'meta' => 'array',
    ];

    // ---------------------------------------------------------------- Relações

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'ticket_priority_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fieldValues(): HasMany
    {
        return $this->hasMany(TicketFieldValue::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }

    public function publicMessages(): HasMany
    {
        return $this->messages()->where('visibility', MessageVisibility::Public->value);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class)->latest('created_at');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(TicketColumnTransition::class)->orderBy('entered_at');
    }

    public function slaTimers(): HasMany
    {
        return $this->hasMany(TicketSlaTimer::class);
    }

    public function firstResponseTimer(): HasOne
    {
        return $this->hasOne(TicketSlaTimer::class)->where('type', SlaTimerType::FirstResponse->value);
    }

    public function resolutionTimer(): HasOne
    {
        return $this->hasOne(TicketSlaTimer::class)->where('type', SlaTimerType::Resolution->value);
    }

    // ---------------------------------------------------------------- Scopes

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Restringe aos tickets que o usuário pode ver, segundo as permissões granulares.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin() || $user->can('tickets.view_all')) {
            return $query;
        }

        $canTeam = $user->can('tickets.view_team');
        $canOwn = $user->can('tickets.view_own');

        if (! $canTeam && ! $canOwn) {
            return $query->whereRaw('1 = 0');
        }

        $teamIds = $canTeam ? $user->teamIds() : [];

        return $query->where(function (Builder $q) use ($user, $teamIds, $canOwn) {
            if ($canOwn) {
                $q->orWhere('assignee_id', $user->id)->orWhere('created_by', $user->id);
            }
            if ($teamIds !== []) {
                $q->orWhereIn('team_id', $teamIds);
            }
        });
    }

    // ---------------------------------------------------------------- Helpers

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** Valor de um campo customizado pela chave (lê da relação carregada quando disponível). */
    public function customValue(string $key): mixed
    {
        $value = $this->fieldValues
            ->first(fn (TicketFieldValue $v) => $v->field?->key === $key);

        return $value?->resolvedValue();
    }
}
