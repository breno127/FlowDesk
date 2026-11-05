<?php

namespace App\Policies;

/** Permissões do recurso App\Models\TicketPriority (catálogo em config/helpdesk.php). */
class TicketPriorityPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'config.priorities';
    }
}
