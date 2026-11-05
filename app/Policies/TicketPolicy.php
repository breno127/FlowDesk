<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['tickets.view_all', 'tickets.view_team', 'tickets.view_own']);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return Ticket::query()->withTrashed()->whereKey($ticket->id)->visibleTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('tickets.create');
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.update') && $this->view($user, $ticket);
    }

    public function move(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.move') && $this->view($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.assign') && $this->view($user, $ticket);
    }

    /** Assumir para si exige apenas edição. */
    public function assignToSelf(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.reply') && $this->view($user, $ticket);
    }

    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.internal_note') && $this->view($user, $ticket);
    }

    /** Enxergar notas internas (e seus anexos) na conversa. */
    public function viewInternalNotes(User $user, Ticket $ticket): bool
    {
        return $user->canAny(['tickets.internal_note', 'tickets.view_all']) && $this->view($user, $ticket);
    }

    public function changePriority(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.change_priority') && $this->view($user, $ticket);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.delete') && $this->view($user, $ticket);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('tickets.delete');
    }

    public function restore(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.restore');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('tickets.restore');
    }

    public function forceDelete(User $user, Ticket $ticket): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
