<?php

namespace App\Services\Automation;

use App\Models\Ticket;
use Illuminate\Support\Str;

/**
 * Avalia condições [{field, operator, value}] contra o ticket + payload do evento.
 */
class ConditionEvaluator
{
    /** Campos lidos do payload do evento, e não do ticket. */
    private const PAYLOAD_FIELDS = ['from_column_id', 'message.visibility', 'message.direction', 'message.channel', 'sla.type'];

    public function passes(array $conditions, string $match, Ticket $ticket, AutomationContext $context): bool
    {
        $conditions = array_values(array_filter($conditions, fn ($c) => ! empty($c['field']) && ! empty($c['operator'])));

        if ($conditions === []) {
            return true;
        }

        $results = array_map(fn (array $c) => $this->evaluate($c, $ticket, $context), $conditions);

        return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    public function evaluate(array $condition, Ticket $ticket, AutomationContext $context): bool
    {
        $field = $condition['field'];
        $operator = $condition['operator'];
        $expected = $condition['value'] ?? null;

        if ($operator === 'changed') {
            return array_key_exists($field, $context->payload['changes'] ?? []);
        }

        $actual = $this->valueOf($field, $ticket, $context);

        return match ($operator) {
            'equals' => $this->normalize($actual) === $this->normalize($this->first($expected)),
            'not_equals' => $this->normalize($actual) !== $this->normalize($this->first($expected)),
            'in' => in_array($this->normalize($actual), $this->list($expected), true),
            'not_in' => ! in_array($this->normalize($actual), $this->list($expected), true),
            'contains' => $actual !== null && Str::contains(mb_strtolower((string) $actual), mb_strtolower((string) $this->first($expected))),
            'not_contains' => $actual === null || ! Str::contains(mb_strtolower((string) $actual), mb_strtolower((string) $this->first($expected))),
            'is_empty' => blank($actual),
            'is_not_empty' => filled($actual),
            default => false,
        };
    }

    private function valueOf(string $field, Ticket $ticket, AutomationContext $context): mixed
    {
        if (in_array($field, self::PAYLOAD_FIELDS, true)) {
            return data_get($context->payload, $field);
        }

        if (str_starts_with($field, 'custom.')) {
            $ticket->loadMissing('fieldValues.field');

            return $ticket->customValue(Str::after($field, 'custom.'));
        }

        return $ticket->getAttribute($field);
    }

    private function normalize(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function first(mixed $value): mixed
    {
        return is_array($value) ? (array_values($value)[0] ?? null) : $value;
    }

    /** @return list<?string> */
    private function list(mixed $value): array
    {
        return array_map(fn ($v) => $this->normalize($v), is_array($value) ? array_values($value) : [$value]);
    }
}
