<?php

namespace App\Policies;

/** Permissões do recurso App\Models\CustomField (catálogo em config/helpdesk.php). */
class CustomFieldPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'config.custom_fields';
    }
}
