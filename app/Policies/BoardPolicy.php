<?php

namespace App\Policies;

/** Permissões do recurso App\Models\Board (catálogo em config/helpdesk.php). */
class BoardPolicy extends PermissionPolicy
{
    protected function managePermission(): string
    {
        return 'kanban.manage_boards';
    }

    protected function viewPermission(): ?string
    {
        return 'kanban.view';
    }
}
