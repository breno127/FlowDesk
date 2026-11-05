<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Política genérica para recursos de configuração: uma permissão de "gestão"
 * libera todas as habilidades; opcionalmente uma permissão só de leitura.
 * Super-admin é liberado no Gate::before (AppServiceProvider).
 */
abstract class PermissionPolicy
{
    abstract protected function managePermission(): string;

    protected function viewPermission(): ?string
    {
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->can($this->managePermission());
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->managePermission());
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->managePermission());
    }

    public function deleteAny(User $user): bool
    {
        return $user->can($this->managePermission());
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->can($this->managePermission());
    }

    public function restoreAny(User $user): bool
    {
        return $user->can($this->managePermission());
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->can($this->managePermission());
    }

    private function canView(User $user): bool
    {
        $view = $this->viewPermission();

        return $user->can($this->managePermission()) || ($view !== null && $user->can($view));
    }
}
