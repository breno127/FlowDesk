<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketFieldValue extends Model
{
    protected $fillable = [
        'ticket_id', 'custom_field_id', 'value_string', 'value_text', 'value_number',
        'value_boolean', 'value_datetime', 'value_json',
    ];

    protected $casts = [
        'value_number' => 'float',
        'value_boolean' => 'boolean',
        'value_datetime' => 'datetime',
        'value_json' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /** Lê o valor da coluna tipada correspondente ao tipo do campo. */
    public function resolvedValue(): mixed
    {
        $type = $this->field?->type;

        if (! $type instanceof CustomFieldType) {
            return null;
        }

        $value = $this->{$type->storageColumn()};

        return match ($type) {
            CustomFieldType::Number => $value === null ? null : (int) $value,
            CustomFieldType::Date => $value?->toDateString(),
            CustomFieldType::DateTime => $value?->toDateTimeString(),
            default => $value,
        };
    }
}
