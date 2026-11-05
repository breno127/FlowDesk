<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Enums\MessageDirection;
use App\Enums\MessageVisibility;
use App\Models\Ticket;
use App\Services\Automation\AutomationContext;
use App\Services\Tickets\TicketService;
use App\Support\TicketTemplate;
use Filament\Forms\Components\Textarea;

class AddInternalNoteAction implements AutomationAction
{
    public function __construct(private readonly TicketService $tickets) {}

    public static function label(): string
    {
        return 'Adicionar nota interna';
    }

    public static function schema(): array
    {
        return [
            Textarea::make('body')->label('Nota')->required()->rows(3)
                ->helperText('Aceita @username para mencionar agentes e placeholders '.implode(' ', array_keys(TicketTemplate::placeholders()))),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $this->tickets->addMessage($ticket, [
            'body' => TicketTemplate::render($data['body'] ?? '', $ticket),
            'visibility' => MessageVisibility::Internal,
            'direction' => MessageDirection::System,
            'meta' => ['automation_rule_id' => $context->rule?->id],
        ]);

        return 'Nota interna adicionada';
    }
}
