<?php

namespace App\Repositories\Contracts;

use App\Models\Team;
use Illuminate\Support\Collection;

interface AgentWorkloadRepositoryInterface
{
    /**
     * Membros disponíveis e ativos da equipe, com pivot (capacity, last_assigned_at).
     *
     * @return Collection<int, \App\Models\User>
     */
    public function availableMembers(Team $team): Collection;

    /**
     * Carga atual = soma dos pesos de prioridade dos tickets abertos atribuídos.
     *
     * @param  list<int>  $userIds
     * @return array<int, int> user_id => peso
     */
    public function openWeightByAgent(array $userIds): array;

    public function touchLastAssigned(Team $team, int $userId): void;
}
