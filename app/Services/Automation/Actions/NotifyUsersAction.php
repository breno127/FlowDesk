<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\AutomationAlertNotification;
use App\Services\Automation\AutomationContext;
use App\Support\TicketTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Notification;

class NotifyUsersAction implements AutomationAction
{
    public static function label(): string
    {
        return 'Notificar usuários específicos';
    }

    public static function schema(): array
    {
        return [
            Select::make('user_ids')->label('Usuários')->multiple()->required()->searchable()
                ->options(fn () => User::query()->agents()->orderBy('name')->pluck('name', 'id')),
            ...static::messageFields(),
        ];
    }

    /** Campos de título/mensagem reutilizados pelas ações de notificação. */
    public static function messageFields(): array
    {
        $help = 'Placeholders: '.implode(' ', array_keys(TicketTemplate::placeholders()));

        return [
            TextInput::make('title')->label('Título')->required()->maxLength(150)->helperText($help),
            Textarea::make('message')->label('Mensagem')->rows(3)->maxLength(2000)->helperText($help),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $users = User::query()->agents()->whereIn('id', $data['user_ids'] ?? [])->get();

        Notification::send($users, new AutomationAlertNotification(
            $ticket,
            TicketTemplate::render($data['title'] ?? '', $ticket),
            TicketTemplate::render($data['message'] ?? '', $ticket),
        ));

        return sprintf('%d usuário(s) notificados', $users->count());
    }
}
