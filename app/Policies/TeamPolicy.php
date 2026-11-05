<?php

namespace App\Policies;

/** Permissões do recurso App\Models\Team (catálogo em config/helpdesk.php). */
class TeamPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'org.manage_teams';
    }
}
