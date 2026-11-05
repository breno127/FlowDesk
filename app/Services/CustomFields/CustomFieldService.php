<?php

namespace App\Services\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\Ticket;
use App\Models\TicketFieldValue;
use App\Models\TicketType;
use Carbon\Carbon;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Collection;

/**
 * Campos customizáveis (EAV com colunas tipadas).
 *
 * Definição: custom_fields + pivot custom_field_ticket_type (ordem, obrigatoriedade).
 * Valores:   ticket_field_values, cada tipo numa coluna própria (indexável).
 */
class CustomFieldService
{
    /** @var array<int, Collection<int, CustomField>> */
    private array $memo = [];

    /** @return Collection<int, CustomField> campos ativos do tipo, com pivot */
    public function fieldsForType(?int $ticketTypeId): Collection
    {
        if (! $ticketTypeId) {
            return collect();
        }

        return $this->memo[$ticketTypeId] ??= TicketType::query()
            ->find($ticketTypeId)
            ?->customFields()
            ->where('custom_fields.is_active', true)
            ->get() ?? collect();
    }

    /**
     * Componentes Filament gerados dinamicamente para o tipo de ticket.
     * Os campos ficam no state path "custom_fields.{key}".
     *
     * @return array<int, Component>
     */
    public function formComponents(?int $ticketTypeId): array
    {
        return $this->fieldsForType($ticketTypeId)
            ->map(fn (CustomField $field) => $this->component($field))
            ->all();
    }

    /** Regras de validação Laravel (uso na API REST). */
    public function validationRules(?int $ticketTypeId): array
    {
        return $this->fieldsForType($ticketTypeId)
            ->mapWithKeys(fn (CustomField $f) => ['custom_fields.'.$f->key => $this->rulesFor($f)])
            ->all();
    }

    /** @return array<string, mixed> key => valor, para preencher formulários */
    public function valuesFor(Ticket $ticket): array
    {
        return $ticket->fieldValues()
            ->with('field')
            ->get()
            ->filter(fn (TicketFieldValue $v) => $v->field !== null)
            ->mapWithKeys(fn (TicketFieldValue $v) => [$v->field->key => $v->resolvedValue()])
            ->all();
    }

    /**
     * Persiste os valores do tipo atual do ticket e remove valores de campos que
     * não pertencem mais ao tipo (ex.: troca de "TI" para "Financeiro").
     *
     * @param  array<string, mixed>  $values  key => valor
     */
    public function save(Ticket $ticket, array $values): void
    {
        $fields = $this->fieldsForType($ticket->ticket_type_id)->keyBy('key');

        $ticket->fieldValues()
            ->whereNotIn('custom_field_id', $fields->pluck('id')->all() ?: [0])
            ->delete();

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];
            $column = $field->type->storageColumn();

            if ($this->isEmpty($value)) {
                $ticket->fieldValues()->where('custom_field_id', $field->id)->delete();

                continue;
            }

            $row = array_fill_keys(
                ['value_string', 'value_text', 'value_number', 'value_boolean', 'value_datetime', 'value_json'],
                null
            );
            $row[$column] = $this->cast($field->type, $value);

            TicketFieldValue::query()->updateOrCreate(
                ['ticket_id' => $ticket->id, 'custom_field_id' => $field->id],
                $row
            );
        }

        $ticket->unsetRelation('fieldValues');
    }

    // ------------------------------------------------------------------ Internos

    private function component(CustomField $field): Component
    {
        $name = 'custom_fields.'.$field->key;
        $required = (bool) $field->pivot?->is_required;
        $rules = $field->validation ?? [];

        $component = match ($field->type) {
            CustomFieldType::Text => TextInput::make($name)->maxLength($rules['max_length'] ?? 512),
            CustomFieldType::Email => TextInput::make($name)->email()->maxLength(512),
            CustomFieldType::Url => TextInput::make($name)->url()->maxLength(512),
            CustomFieldType::Textarea => Textarea::make($name)->rows(4)->maxLength($rules['max_length'] ?? 65535),
            CustomFieldType::Number => TextInput::make($name)->integer()
                ->minValue($rules['min'] ?? null)->maxValue($rules['max'] ?? null),
            CustomFieldType::Decimal => TextInput::make($name)->numeric()->inputMode('decimal')
                ->minValue($rules['min'] ?? null)->maxValue($rules['max'] ?? null),
            CustomFieldType::Select => Select::make($name)->options($field->optionMap())->native(false)->searchable(),
            CustomFieldType::MultiSelect => Select::make($name)->options($field->optionMap())->multiple()->native(false),
            CustomFieldType::Radio => Radio::make($name)->options($field->optionMap()),
            CustomFieldType::Checkbox => Checkbox::make($name),
            CustomFieldType::Date => DatePicker::make($name)->native(false),
            CustomFieldType::DateTime => DateTimePicker::make($name)->native(false)->seconds(false),
        };

        if (! empty($rules['regex']) && method_exists($component, 'regex')) {
            $component->regex($rules['regex']);
        }

        if (method_exists($component, 'placeholder') && $field->placeholder) {
            $component->placeholder($field->placeholder);
        }

        return $component
            ->label($field->label)
            ->helperText($field->help_text)
            ->required($required)
            ->default($field->default_value)
            ->columnSpan($field->pivot?->column_span ?? 1);
    }

    private function rulesFor(CustomField $field): array
    {
        $rules = [$field->pivot?->is_required ? 'required' : 'nullable'];
        $extra = $field->validation ?? [];

        $rules = [...$rules, ...match ($field->type) {
            CustomFieldType::Text, CustomFieldType::Textarea => ['string', 'max:'.($extra['max_length'] ?? 65535)],
            CustomFieldType::Email => ['email', 'max:512'],
            CustomFieldType::Url => ['url', 'max:512'],
            CustomFieldType::Number => ['integer'],
            CustomFieldType::Decimal => ['numeric'],
            CustomFieldType::Select, CustomFieldType::Radio => ['in:'.implode(',', array_keys($field->optionMap()))],
            CustomFieldType::MultiSelect => ['array'],
            CustomFieldType::Checkbox => ['boolean'],
            CustomFieldType::Date, CustomFieldType::DateTime => ['date'],
        }];

        if (isset($extra['min'])) {
            $rules[] = 'min:'.$extra['min'];
        }
        if (isset($extra['max'])) {
            $rules[] = 'max:'.$extra['max'];
        }
        if (! empty($extra['regex'])) {
            $rules[] = 'regex:'.$extra['regex'];
        }

        return $rules;
    }

    private function cast(CustomFieldType $type, mixed $value): mixed
    {
        return match ($type) {
            CustomFieldType::Number => (int) $value,
            CustomFieldType::Decimal => (float) $value,
            CustomFieldType::Checkbox => (bool) $value,
            CustomFieldType::Date, CustomFieldType::DateTime => Carbon::parse($value),
            CustomFieldType::MultiSelect => array_values((array) $value),
            default => (string) $value,
        };
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
