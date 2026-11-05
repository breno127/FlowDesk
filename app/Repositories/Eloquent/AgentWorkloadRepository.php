<?php

namespace App\Repositories\Eloquent;

use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Ticket;
use App\Repositories\Contracts\AgentWorkloadRepositoryInterface;
use Illuminate\Support\Collection;

class AgentWorkloadRepository implements AgentWorkloadRepositoryInterface
{
    public function availableMembers(Team $team): Collection
    {
        return $team->members()
            ->where('users.is_active', true)
            ->where('users.is_agent', true)
            ->wherePivot('is_available', true)
            ->get();
    }

    public function openWeightByAgent(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $weights = Ticket::query()
            ->open()
            ->whereIn('tickets.assignee_id', $userIds)
            ->join('ticket_priorities', 'ticket_priorities.id', '=', 'tickets.ticket_priority_id')
            ->groupBy('tickets.assignee_id')
            ->selectRaw('tickets.assignee_id as user_id, SUM(ticket_priorities.weight) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return array_replace(array_fill_keys($userIds, 0), $weights);
    }

    public function touchLastAssigned(Team $team, int $userId): void
    {
        TeamMembership::query()
            ->where('team_id', $team->id)
            ->where('user_id', $userId)
            ->update(['last_assigned_at' => now()]);
    }
}
