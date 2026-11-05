<?php

namespace App\Services\Automation\Actions;

use App\Contracts\AutomationAction;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Services\Automation\AutomationContext;
use App\Services\Tickets\TicketService;
use Filament\Forms\Components\Select;

class MoveToColumnAction implements AutomationAction
{
    public function __construct(private readonly TicketService $tickets) {}

    public static function label(): string
    {
        return 'Mover para coluna';
    }

    public static function schema(): array
    {
        return [
            Select::make('board_column_id')->label('Coluna')->required()->searchable()
                ->options(fn () => BoardColumn::query()->with('board:id,name')->orderBy('board_id')->orderBy('position')->get()
                    ->mapWithKeys(fn (BoardColumn $c) => [$c->id => $c->board->name.' › '.$c->name])),
        ];
    }

    public function execute(Ticket $ticket, array $data, AutomationContext $context): string
    {
        $column = BoardColumn::query()->findOrFail($data['board_column_id']);

        if ($column->board_id !== $ticket->board_id) {
            return sprintf('Coluna "%s" é de outro quadro — ignorado', $column->name);
        }
        if ($column->id === $ticket->board_column_id) {
            return 'Já está na coluna';
        }

        $this->tickets->moveToColumn($ticket, $column, null, null, enforceWip: false);

        return 'Movido para '.$column->name;
    }
}
