<?php

namespace App\Policies;

/** Permissões do recurso App\Models\SlaPolicy (catálogo em config/helpdesk.php). */
class SlaPolicyPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'sla.manage';
    }
}
