<?php

namespace App\Policies;

/** Permissões do recurso Spatie\Permission\Models\Role (catálogo em config/helpdesk.php). */
class RolePolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'org.manage_roles';
    }
}
