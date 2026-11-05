<?php

namespace App\Services\Sla;

use App\Models\SlaPolicy;
use App\Models\SlaTarget;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Escolhe a política de SLA aplicável a um ticket.
 *
 * Critérios null na política = "qualquer valor". Entre as políticas compatíveis
 * que possuem meta para a prioridade do ticket, vence a mais específica
 * (peso: cliente 4 > tipo 2 > departamento 1) e, no empate, a menor `precedence`.
 */
class SlaPolicyResolver
{
    public function resolve(Ticket $ticket): ?SlaTarget
    {
        if (! $ticket->ticket_priority_id) {
            return null;
        }

        $policy = SlaPolicy::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $this->matchesOrAny($q, 'client_company_id', $ticket->client_company_id))
            ->where(fn (Builder $q) => $this->matchesOrAny($q, 'department_id', $ticket->department_id))
            ->where(fn (Builder $q) => $this->matchesOrAny($q, 'ticket_type_id', $ticket->ticket_type_id))
            ->whereHas('targets', fn (Builder $q) => $q->where('ticket_priority_id', $ticket->ticket_priority_id))
            ->orderByRaw(
                '(CASE WHEN client_company_id IS NULL THEN 0 ELSE 4 END'
                .' + CASE WHEN ticket_type_id IS NULL THEN 0 ELSE 2 END'
                .' + CASE WHEN department_id IS NULL THEN 0 ELSE 1 END) DESC'
            )
            ->orderBy('precedence')
            ->orderBy('id')
            ->first();

        if (! $policy) {
            return null;
        }

        return $policy->targets()
            ->with('policy.schedule')
            ->where('ticket_priority_id', $ticket->ticket_priority_id)
            ->first();
    }

    private function matchesOrAny(Builder $query, string $column, ?int $value): void
    {
        $query->whereNull($column);

        if ($value !== null) {
            $query->orWhere($column, $value);
        }
    }
}
