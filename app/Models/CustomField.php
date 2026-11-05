<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomField extends Model
{
    protected $fillable = [
        'label', 'key', 'type', 'options', 'validation', 'placeholder', 'help_text', 'default_value', 'is_active',
    ];

    protected $casts = [
        'type' => CustomFieldType::class,
        'options' => 'array',
        'validation' => 'array',
        'is_active' => 'boolean',
    ];

    public function ticketTypes(): BelongsToMany
    {
        return $this->belongsToMany(TicketType::class)
            ->withPivot(['is_required', 'visible_to_customer', 'sort', 'column_span'])
            ->withTimestamps();
    }

    public function values(): HasMany
    {
        return $this->hasMany(TicketFieldValue::class);
    }

    /** @return array<string, string> value => label */
    public function optionMap(): array
    {
        return collect($this->options ?? [])
            ->filter(fn ($o) => filled($o['value'] ?? null))
            ->mapWithKeys(fn ($o) => [(string) $o['value'] => (string) ($o['label'] ?? $o['value'])])
            ->all();
    }
}
