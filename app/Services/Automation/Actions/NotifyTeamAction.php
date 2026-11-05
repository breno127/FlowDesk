<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Team;
use App\Models\Ticket;
use App\Notifications\AutomationAlertNotification;
use App\Services\Automation\AutomationContext;
use App\Support\TicketTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Notification;

class NotifyTeamAction implements AutomationAction
{
    public static function label(): string
    {
        return 'Notificar equipe';
    }

    public static function schema(): array
    {
        return [
            Select::make('team_id')->label('Equipe')->placeholder('Equipe do ticket')
                ->options(fn () => Team::query()->orderBy('name')->pluck('name', 'id')),
            Toggle::make('leads_only')->label('Somente líderes'),
            ...NotifyUsersAction::messageFields(),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $team = Team::query()->find($data['team_id'] ?? $ticket->team_id);

        if (! $team) {
            return 'Ticket sem equipe — nada a notificar';
        }

        $users = $team->members()
            ->where('users.is_active', true)
            ->when($data['leads_only'] ?? false, fn ($q) => $q->wherePivot('is_lead', true))
            ->get();

        Notification::send($users, new AutomationAlertNotification(
            $ticket,
            TicketTemplate::render($data['title'] ?? '', $ticket),
            TicketTemplate::render($data['message'] ?? '', $ticket),
        ));

        return sprintf('%d membro(s) de "%s" notificados', $users->count(), $team->name);
    }
}
