<?php

namespace App\Support;

use App\Models\Ticket;

/**
 * Substitui placeholders em textos configurados no painel (automação, notificações).
 * Placeholders: {number} {subject} {column} {priority} {assignee} {team} {company} {contact} {type}
 */
final class TicketTemplate
{
    /** @return array<string, string> placeholder => descrição */
    public static function placeholders(): array
    {
        return [
            '{number}' => 'Número do ticket',
            '{subject}' => 'Assunto',
            '{column}' => 'Coluna atual',
            '{priority}' => 'Prioridade',
            '{assignee}' => 'Responsável',
            '{team}' => 'Equipe',
            '{company}' => 'Empresa cliente',
            '{contact}' => 'Contato',
            '{type}' => 'Tipo de ticket',
        ];
    }

    public static function render(?string $template, Ticket $ticket): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $ticket->loadMissing(['column', 'priority', 'assignee', 'team', 'company', 'contact', 'type']);

        return strtr($template, [
            '{number}' => $ticket->number,
            '{subject}' => $ticket->subject,
            '{column}' => $ticket->column?->name ?? '',
            '{priority}' => $ticket->priority?->name ?? '',
            '{assignee}' => $ticket->assignee?->name ?? 'não atribuído',
            '{team}' => $ticket->team?->name ?? '',
            '{company}' => $ticket->company?->name ?? '',
            '{contact}' => $ticket->contact?->name ?? '',
            '{type}' => $ticket->type?->name ?? '',
        ]);
    }
}
