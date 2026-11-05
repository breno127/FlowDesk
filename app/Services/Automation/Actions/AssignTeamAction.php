<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\Automation\AutomationContext;
use App\Services\Tickets\TicketService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

class AssignTeamAction implements AutomationAction
{
    public function __construct(private readonly TicketService $tickets) {}

    public static function label(): string
    {
        return 'Encaminhar para equipe (escalonar)';
    }

    public static function schema(): array
    {
        return [
            Select::make('team_id')->label('Equipe')->placeholder('Equipe de escalonamento da equipe atual')
                ->options(fn () => Team::query()->where('is_active', true)->orderBy('tier')->orderBy('name')->pluck('name', 'id')),
            Toggle::make('auto_assign')->label('Distribuir automaticamente na nova equipe')->default(true),
            Toggle::make('unassign')->label('Remover responsável atual')->default(true),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $team = ! empty($data['team_id'])
            ? Team::query()->findOrFail($data['team_id'])
            : $ticket->team?->escalationTeam;

        if (! $team) {
            return 'Nenhuma equipe de destino/escalonamento definida';
        }

        $update = ['team_id' => $team->id];
        if ($data['unassign'] ?? true) {
            $update['assignee_id'] = null;
        }

        $ticket = $this->tickets->update($ticket, $update);

        $agent = ($data['auto_assign'] ?? true) ? $this->tickets->autoAssign($ticket, $team) : null;

        return sprintf('Equipe → %s%s', $team->name, $agent ? ' / agente → '.$agent->name : '');
    }
}
