<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomFieldType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Decimal = 'decimal';
    case Email = 'email';
    case Url = 'url';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Date = 'date';
    case DateTime = 'datetime';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Texto curto',
            self::Textarea => 'Texto longo',
            self::Number => 'Número inteiro',
            self::Decimal => 'Número decimal',
            self::Email => 'E-mail',
            self::Url => 'URL',
            self::Select => 'Lista (única)',
            self::MultiSelect => 'Lista (múltipla)',
            self::Radio => 'Opções (radio)',
            self::Checkbox => 'Sim/Não',
            self::Date => 'Data',
            self::DateTime => 'Data e hora',
        };
    }

    /** Coluna tipada de ticket_field_values onde o valor é persistido. */
    public function storageColumn(): string
    {
        return match ($this) {
            self::Text, self::Email, self::Url, self::Select, self::Radio => 'value_string',
            self::Textarea => 'value_text',
            self::Number, self::Decimal => 'value_number',
            self::Checkbox => 'value_boolean',
            self::Date, self::DateTime => 'value_datetime',
            self::MultiSelect => 'value_json',
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect, self::Radio], true);
    }
}
