<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Jobs\DispatchOutgoingWebhook;
use App\Models\Ticket;
use App\Services\Automation\AutomationContext;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class FireWebhookAction implements AutomationAction
{
    public static function label(): string
    {
        return 'Disparar webhook (HTTP)';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('url')->label('URL')->url()->required()->maxLength(2048),
            Select::make('method')->label('Método')->options(['POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH'])->default('POST')->required(),
            KeyValue::make('headers')->label('Headers adicionais')->keyLabel('Header')->valueLabel('Valor'),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $ticket->loadMissing(['column:id,name', 'priority:id,name,slug', 'assignee:id,name,email', 'team:id,name']);

        DispatchOutgoingWebhook::dispatch(
            url: $data['url'],
            method: $data['method'] ?? 'POST',
            payload: [
                'event' => $context->event,
                'rule_id' => $context->rule?->id,
                'occurred_at' => now()->toIso8601String(),
                'context' => $context->payload,
                'ticket' => [
                    'id' => $ticket->id,
                    'number' => $ticket->number,
                    'subject' => $ticket->subject,
                    'column' => $ticket->column?->name,
                    'priority' => $ticket->priority?->slug,
                    'assignee' => $ticket->assignee?->only(['id', 'name', 'email']),
                    'team' => $ticket->team?->name,
                    'resolved_at' => $ticket->resolved_at?->toIso8601String(),
                ],
            ],
            headers: array_filter((array) ($data['headers'] ?? [])),
        );

        return 'Webhook enfileirado: '.$data['url'];
    }
}
