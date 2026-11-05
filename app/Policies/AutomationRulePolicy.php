<?php

namespace App\Policies;

/** Permissões do recurso App\Models\AutomationRule (catálogo em config/helpdesk.php). */
class AutomationRulePolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'config.automations';
    }
}
