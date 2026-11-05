<?php

namespace App\Policies;

/** Permissões do recurso App\Models\Contact (catálogo em config/helpdesk.php). */
class ContactPolicy extends PermissionPolicy
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
