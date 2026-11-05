<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo campo ↔ tipo de ticket (ordem, obrigatoriedade, largura).
 * Modelo próprio para permitir Repeater ordenável no Filament.
 */
class CustomFieldTicketType extends Model
{
    protected $table = 'custom_field_ticket_type';

    protected $fillable = ['custom_field_id', 'ticket_type_id', 'is_required', 'visible_to_customer', 'sort', 'column_span'];

    protected $casts = [
        'is_required' => 'boolean',
        'visible_to_customer' => 'boolean',
        'sort' => 'integer',
        'column_span' => 'integer',
    ];

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }
}
