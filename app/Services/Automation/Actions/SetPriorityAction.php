<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Services\Automation\AutomationContext;
use App\Services\Tickets\TicketService;
use Filament\Forms\Components\Select;

class SetPriorityAction implements AutomationAction
{
    public function __construct(private readonly TicketService $tickets) {}

    public static function label(): string
    {
        return 'Definir prioridade';
    }

    public static function schema(): array
    {
        return [
            Select::make('ticket_priority_id')->label('Prioridade')->required()
                ->options(fn () => TicketPriority::query()->orderBy('level')->pluck('name', 'id')),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $priority = TicketPriority::query()->findOrFail($data['ticket_priority_id']);
        $this->tickets->update($ticket, ['ticket_priority_id' => $priority->id]);

        return 'Prioridade → '.$priority->name;
    }
}
