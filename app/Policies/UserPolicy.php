<?php

namespace App\Policies;

/** Permissões do recurso App\Models\User (catálogo em config/helpdesk.php). */
class UserPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'org.manage_users';
    }
}
