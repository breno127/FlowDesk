<?php

namespace App\Repositories\Contracts;

use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

interface TicketRepositoryInterface
{
    /**
     * Cards do quadro agrupados por coluna, já filtrados pela visibilidade do usuário.
     *
     * @param  array{search?: ?string, assignee_id?: int|string|null, team_id?: ?int, ticket_priority_id?: ?int, ticket_type_id?: ?int, sla_state?: ?string}  $filters
     * @return Collection<int, Collection<int, Ticket>> column_id => tickets
     */
    public function kanbanCards(Board $board, User $viewer, array $filters, int $perColumn): Collection;

    /** @return array<int, int> column_id => total de tickets (ignorando filtros) */
    public function countsByColumn(Board $board): array;

    public function countInColumn(int $columnId): int;

    public function nextPosition(int $columnId): int;

    /**
     * Insere o ticket na coluna/posição e recompacta as posições das colunas afetadas.
     */
    public function placeInColumn(Ticket $ticket, int $columnId, ?int $position): void;

    public function lockForUpdate(int $ticketId): Ticket;
}
