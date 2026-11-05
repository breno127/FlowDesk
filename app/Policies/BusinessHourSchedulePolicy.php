<?php

namespace App\Policies;

/** Permissões do recurso App\Models\BusinessHourSchedule (catálogo em config/helpdesk.php). */
class BusinessHourSchedulePolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'sla.manage_business_hours';
    }

    protected function viewPermission(): ?string
    {
        return 'sla.manage';
    }
}
