<?php

namespace App\Policies;

/** Permissões do recurso App\Models\ClientCompany (catálogo em config/helpdesk.php). */
class ClientCompanyPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'customers.manage';
    }

    protected function viewPermission(): ?string
    {
        return 'customers.view';
    }
}
