<?php

namespace App\Policies;

/** Permissões do recurso App\Models\TicketType (catálogo em config/helpdesk.php). */
class TicketTypePolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'config.ticket_types';
    }
}
