<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaPolicy extends Model
{
    protected $fillable = [
        'name', 'description', 'business_hour_schedule_id', 'client_company_id', 'department_id',
        'ticket_type_id', 'precedence', 'is_active',
    ];

    protected $casts = ['precedence' => 'integer', 'is_active' => 'boolean'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BusinessHourSchedule::class, 'business_hour_schedule_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SlaTarget::class);
    }

    /** Quantidade de critérios preenchidos — quanto mais específico, maior a prioridade. */
    public function specificity(): int
    {
        return (int) filled($this->client_company_id)
            + (int) filled($this->department_id)
            + (int) filled($this->ticket_type_id);
    }
}
