<?php

namespace App\Services\Assignment;

use App\Enums\RoutingStrategy;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Contracts\AgentWorkloadRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Distribuição automática de tickets.
 *
 * - load_balanced: escolhe o agente com menor (carga / capacidade), onde carga é a
 *   soma dos pesos de prioridade dos tickets abertos. Empate → quem recebeu há mais tempo.
 * - round_robin: rodízio puro por last_assigned_at (respeitando o teto de carga).
 * - manual: nunca atribui automaticamente.
 *
 * A linha da equipe é travada (SELECT ... FOR UPDATE) para que duas distribuições
 * simultâneas na mesma equipe não escolham o mesmo agente com base em carga desatualizada.
 */
class AssignmentService
{
    public function __construct(private readonly AgentWorkloadRepositoryInterface $workload) {}

    public function pickAgent(Ticket $ticket, ?Team $team = null): ?User
    {
        $team ??= $ticket->team;

        if (! $team || ! $team->is_active || $team->routing_strategy === RoutingStrategy::Manual) {
            return null;
        }

        return DB::transaction(function () use ($ticket, $team) {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            $members = $this->workload->availableMembers($team);

            if ($members->isEmpty()) {
                return null;
            }

            $ticketWeight = max(1, (int) ($ticket->priority?->weight ?? 1));
            $loads = $this->workload->openWeightByAgent($members->pluck('id')->map(fn ($id) => (int) $id)->all());

            $candidates = $members
                ->map(function (User $user) use ($loads, $team, $ticketWeight) {
                    $capacity = max(0.01, (float) $user->pivot->capacity);
                    $load = $loads[$user->id] ?? 0;
                    $cap = $team->max_weight_per_agent !== null ? $team->max_weight_per_agent * $capacity : null;

                    return [
                        'user' => $user,
                        'load' => $load,
                        'score' => $load / $capacity,
                        'fits' => $cap === null || ($load + $ticketWeight) <= $cap,
                        'last' => $user->pivot->last_assigned_at?->getTimestamp() ?? 0,
                    ];
                })
                ->filter(fn (array $c) => $c['fits'])
                ->values();

            if ($candidates->isEmpty()) {
                return null;
            }

            $sorted = $team->routing_strategy === RoutingStrategy::RoundRobin
                ? $candidates->sortBy([['last', 'asc'], [fn ($a, $b) => $a['user']->id <=> $b['user']->id]])
                : $candidates->sortBy([['score', 'asc'], ['last', 'asc'], [fn ($a, $b) => $a['user']->id <=> $b['user']->id]]);

            /** @var User $chosen */
            $chosen = $sorted->first()['user'];

            $this->workload->touchLastAssigned($team, $chosen->id);

            return $chosen;
        });
    }

    /**
     * Visão de carga atual da equipe (usada no painel de equipes e dashboards).
     *
     * @return list<array{user_id:int, name:string, load:int, capacity:float, available:bool}>
     */
    public function teamWorkload(Team $team): array
    {
        $members = $team->members()->get();
        $loads = $this->workload->openWeightByAgent($members->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $members->map(fn (User $u) => [
            'user_id' => $u->id,
            'name' => $u->name,
            'load' => $loads[$u->id] ?? 0,
            'capacity' => (float) $u->pivot->capacity,
            'available' => (bool) $u->pivot->is_available,
        ])->values()->all();
    }
}
