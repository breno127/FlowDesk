<?php

namespace App\Policies;

/** Permissões do recurso App\Models\Department (catálogo em config/helpdesk.php). */
class DepartmentPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'org.manage_departments';
    }

    protected function viewPermission(): ?string
    {
        return 'org.manage_teams';
    }
}
