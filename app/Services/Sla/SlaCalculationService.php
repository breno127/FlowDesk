<?php

namespace App\Services\Sla;

use App\Enums\SlaBehavior;
use App\Enums\SlaState;
use App\Enums\SlaTimerStatus;
use App\Enums\SlaTimerType;
use App\Events\SlaBreached;
use App\Events\SlaWarningReached;
use App\Models\BoardColumn;
use App\Models\SlaTarget;
use App\Models\Ticket;
use App\Models\TicketSlaTimer;
use App\Repositories\Contracts\SlaTimerRepositoryInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Motor de SLA.
 *
 * Modelo de dados de um timer:
 *   elapsed_seconds     = segundos úteis acumulados em segmentos já encerrados
 *   segment_started_at  = início do segmento em execução (null quando pausado)
 *   due_at / warning_due_at = projeções recalculadas a cada start/resume/alteração
 *
 * Assim o consumo "ao vivo" é elapsed_seconds + tempoÚtil(segment_started_at, agora),
 * e a detecção de violação é uma consulta indexada (status, due_at) — sem
 * recalcular todos os tickets a cada minuto.
 */
class SlaCalculationService
{
    public function __construct(
        private readonly SlaPolicyResolver $resolver,
        private readonly BusinessTimeCalculator $calculator,
        private readonly SlaTimerRepositoryInterface $timers,
    ) {}

    // ------------------------------------------------------------------ Ciclo de vida

    /**
     * Cria os timers do ticket conforme a política aplicável.
     * Deve ser chamado dentro da transação de criação do ticket.
     */
    public function initialize(Ticket $ticket, ?CarbonInterface $at = null): void
    {
        $at = CarbonImmutable::instance($at ?? now());
        $target = $this->resolver->resolve($ticket);

        $ticket->forceFill(['sla_policy_id' => $target?->sla_policy_id])->saveQuietly();

        if (! $target) {
            return;
        }

        $behavior = $ticket->column?->sla_behavior ?? SlaBehavior::Run;

        foreach (SlaTimerType::cases() as $type) {
            $minutes = $target->minutesFor($type);

            if (! $minutes) {
                continue;
            }
            if ($type === SlaTimerType::FirstResponse && $ticket->first_response_at !== null) {
                continue;
            }

            $timer = new TicketSlaTimer([
                'ticket_id' => $ticket->id,
                'type' => $type,
                'sla_policy_id' => $target->sla_policy_id,
                'business_hour_schedule_id' => $target->policy->business_hour_schedule_id,
                'target_seconds' => $minutes * 60,
                'warning_percent' => $target->effectiveWarningPercent(),
                'status' => SlaTimerStatus::Running,
                'elapsed_seconds' => 0,
                'started_at' => $at,
                'segment_started_at' => $at,
            ]);

            match ($behavior) {
                SlaBehavior::Run => $this->project($timer),
                SlaBehavior::Pause => $this->markPaused($timer, $at, 'Coluna inicial pausa o SLA'),
                SlaBehavior::Stop => $this->markCompleted($timer, $at),
            };

            $timer->save();
        }
    }

    /** Pausa todos os timers em execução (ex.: "Aguardando Cliente"). */
    public function pause(Ticket $ticket, ?string $reason = null, ?CarbonInterface $at = null): void
    {
        $at = CarbonImmutable::instance($at ?? now());

        $this->lockedTimers($ticket, [SlaTimerStatus::Running])->each(function (TicketSlaTimer $timer) use ($at, $reason) {
            $this->markPaused($timer, $at, $reason);
            $timer->save();
        });
    }

    /** Retoma timers pausados, reprojetando vencimento a partir de agora. */
    public function resume(Ticket $ticket, ?CarbonInterface $at = null): void
    {
        $at = CarbonImmutable::instance($at ?? now());

        $this->lockedTimers($ticket, [SlaTimerStatus::Paused])->each(function (TicketSlaTimer $timer) use ($at) {
            $timer->status = SlaTimerStatus::Running;
            $timer->segment_started_at = $at;
            $timer->paused_at = null;
            $timer->pause_reason = null;
            $this->project($timer);
            $timer->save();
        });
    }

    /** Encerra um timer específico (ex.: primeira resposta enviada). */
    public function complete(Ticket $ticket, SlaTimerType $type, ?CarbonInterface $at = null): void
    {
        $at = CarbonImmutable::instance($at ?? now());

        $this->lockedTimers($ticket, [SlaTimerStatus::Running, SlaTimerStatus::Paused], $type)
            ->each(function (TicketSlaTimer $timer) use ($at) {
                $this->markCompleted($timer, $at);
                $timer->save();
            });
    }

    /** Ticket resolvido: encerra todos os timers ativos. */
    public function completeAll(Ticket $ticket, ?CarbonInterface $at = null): void
    {
        foreach (SlaTimerType::cases() as $type) {
            $this->complete($ticket, $type, $at);
        }
    }

    /**
     * Ticket reaberto: o timer de solução volta a contar de onde parou
     * (o tempo já consumido é preservado). Primeira resposta não reabre.
     */
    public function reopen(Ticket $ticket, ?CarbonInterface $at = null): void
    {
        $at = CarbonImmutable::instance($at ?? now());

        $this->lockedTimers($ticket, [SlaTimerStatus::Completed], SlaTimerType::Resolution)
            ->each(function (TicketSlaTimer $timer) use ($at) {
                $timer->status = SlaTimerStatus::Running;
                $timer->completed_at = null;
                $timer->segment_started_at = $at;
                $this->project($timer);
                $timer->save();
            });

        if ($ticket->slaTimers()->doesntExist()) {
            $this->initialize($ticket, $at);
        }
    }

    /** Aplica o comportamento de SLA da coluna de destino. */
    public function applyColumnBehavior(Ticket $ticket, BoardColumn $to, ?CarbonInterface $at = null): void
    {
        match ($to->sla_behavior) {
            SlaBehavior::Run => $this->resume($ticket, $at),
            SlaBehavior::Pause => $this->pause($ticket, sprintf('Coluna "%s"', $to->name), $at),
            SlaBehavior::Stop => $this->completeAll($ticket, $at),
        };
    }

    /**
     * Prioridade/tipo/cliente/departamento mudou: reaplica a política mantendo
     * o tempo já consumido. Pode "desfazer" violação se a nova meta for maior.
     */
    public function recalculate(Ticket $ticket, ?CarbonInterface $at = null): void
    {
        $at = CarbonImmutable::instance($at ?? now());
        $target = $this->resolver->resolve($ticket);

        $ticket->forceFill(['sla_policy_id' => $target?->sla_policy_id])->saveQuietly();

        $active = $this->lockedTimers($ticket, [SlaTimerStatus::Running, SlaTimerStatus::Paused]);

        foreach ($active as $timer) {
            $minutes = $target?->minutesFor($timer->type);

            if (! $target || ! $minutes) {
                $timer->status = SlaTimerStatus::Cancelled;
                $timer->completed_at = $at;
                $timer->due_at = $timer->warning_due_at = null;
                $timer->save();

                continue;
            }

            // Fecha o segmento corrente com a agenda antiga antes de trocar a meta
            if ($timer->status === SlaTimerStatus::Running) {
                $timer->elapsed_seconds = $this->elapsed($timer, $at);
                $timer->segment_started_at = $at;
            }

            $timer->fill([
                'sla_policy_id' => $target->sla_policy_id,
                'business_hour_schedule_id' => $target->policy->business_hour_schedule_id,
                'target_seconds' => $minutes * 60,
                'warning_percent' => $target->effectiveWarningPercent(),
            ]);
            $timer->unsetRelation('schedule');

            if ($timer->elapsed_seconds < $timer->target_seconds) {
                $timer->breached_at = null;
            }
            if ($timer->elapsed_seconds < $this->warningSeconds($timer)) {
                $timer->warned_at = null;
            }

            if ($timer->status === SlaTimerStatus::Running) {
                $this->project($timer);
            }

            $timer->save();
        }

        // Política nova pode definir metas antes inexistentes
        if ($target && $active->isEmpty() && $ticket->isOpen() && $ticket->slaTimers()->doesntExist()) {
            $this->initialize($ticket, $at);
        }
    }

    // ------------------------------------------------------------------ Leitura

    /** Segundos úteis consumidos até $at. */
    public function elapsed(TicketSlaTimer $timer, ?CarbonInterface $at = null): int
    {
        $elapsed = $timer->elapsed_seconds;

        if ($timer->status === SlaTimerStatus::Running && $timer->segment_started_at) {
            $elapsed += $this->calculator->secondsBetween($timer->schedule, $timer->segment_started_at, $at ?? now());
        }

        return $elapsed;
    }

    public function snapshot(TicketSlaTimer $timer, ?CarbonInterface $at = null): SlaSnapshot
    {
        $at ??= now();
        $elapsed = $this->elapsed($timer, $at);
        $target = max($timer->target_seconds, 1);
        $percent = $elapsed / $target * 100;
        $breached = $timer->breached_at !== null || $elapsed >= $target;

        $state = match (true) {
            $timer->status === SlaTimerStatus::Completed => $breached ? SlaState::Breached : SlaState::Met,
            $breached => SlaState::Breached,
            $timer->status === SlaTimerStatus::Paused => SlaState::Paused,
            $percent >= (int) config('helpdesk.sla.critical_percent', 90) => SlaState::Critical,
            $percent >= $timer->warning_percent => SlaState::Warning,
            default => SlaState::Ok,
        };

        $ticking = $timer->status === SlaTimerStatus::Running
            && $this->calculator->isBusinessTime($timer->schedule, $at);

        return new SlaSnapshot(
            type: $timer->type,
            state: $state,
            targetSeconds: $timer->target_seconds,
            elapsedSeconds: $elapsed,
            remainingSeconds: $timer->target_seconds - $elapsed,
            percent: $percent,
            dueAt: $timer->due_at ? CarbonImmutable::instance($timer->due_at) : null,
            ticking: $ticking,
            warningPercent: $timer->warning_percent,
            criticalPercent: (int) config('helpdesk.sla.critical_percent', 90),
        );
    }

    /**
     * Snapshots dos timers ativos (ou do último encerrado) de um ticket.
     *
     * @return array<string, SlaSnapshot> indexado por tipo
     */
    public function snapshotsFor(Ticket $ticket, ?CarbonInterface $at = null): array
    {
        $timers = $ticket->relationLoaded('slaTimers') ? $ticket->slaTimers : $ticket->slaTimers()->with('schedule')->get();

        return $timers
            ->reject(fn (TicketSlaTimer $t) => $t->status === SlaTimerStatus::Cancelled)
            ->mapWithKeys(fn (TicketSlaTimer $t) => [$t->type->value => $this->snapshot($t, $at)])
            ->all();
    }

    /** O snapshot mais urgente do ticket (para o badge principal do card). */
    public function mostUrgent(Ticket $ticket, ?CarbonInterface $at = null): ?SlaSnapshot
    {
        $snapshots = collect($this->snapshotsFor($ticket, $at));

        $active = $snapshots->reject(fn (SlaSnapshot $s) => $s->state === SlaState::Met);

        return ($active->isNotEmpty() ? $active : $snapshots)
            ->sortBy(fn (SlaSnapshot $s) => $s->state === SlaState::Paused ? PHP_INT_MAX : $s->remainingSeconds)
            ->first();
    }

    // ------------------------------------------------------------------ Varredura (job agendado)

    /**
     * Detecta alertas e violações cujo instante projetado já passou.
     * Idempotente: usa UPDATE condicional, seguro com múltiplos workers.
     *
     * @return array{warnings: int, breaches: int}
     */
    public function scan(?CarbonInterface $at = null): array
    {
        $at = CarbonImmutable::instance($at ?? now());
        $chunk = (int) config('helpdesk.sla.scan_chunk', 500);
        $counts = ['warnings' => 0, 'breaches' => 0];

        $this->timers->eachDueForWarning($at, $chunk, function (TicketSlaTimer $timer) use ($at, &$counts) {
            if ($this->timers->markWarned($timer, $at)) {
                $counts['warnings']++;
                SlaWarningReached::dispatch($timer->ticket, $timer->fresh(['schedule']));
            }
        });

        $this->timers->eachDueForBreach($at, $chunk, function (TicketSlaTimer $timer) use (&$counts) {
            // Instante real da violação = due_at projetado (não o horário da varredura)
            if ($this->timers->markBreached($timer, CarbonImmutable::instance($timer->due_at))) {
                $counts['breaches']++;
                SlaBreached::dispatch($timer->ticket, $timer->fresh(['schedule']));
            }
        });

        return $counts;
    }

    // ------------------------------------------------------------------ Internos

    /** Recalcula due_at e warning_due_at de um timer em execução. */
    private function project(TicketSlaTimer $timer): void
    {
        $start = $timer->segment_started_at ?? now();
        $schedule = $timer->schedule;

        $remaining = $timer->target_seconds - $timer->elapsed_seconds;
        $warningRemaining = $this->warningSeconds($timer) - $timer->elapsed_seconds;

        $timer->due_at = $remaining > 0
            ? $this->calculator->addSeconds($schedule, $start, $remaining)
            : $start;

        $timer->warning_due_at = $warningRemaining > 0
            ? $this->calculator->addSeconds($schedule, $start, $warningRemaining)
            : $start;
    }

    private function markPaused(TicketSlaTimer $timer, CarbonImmutable $at, ?string $reason): void
    {
        $timer->elapsed_seconds = $this->elapsed($timer, $at);
        $timer->status = SlaTimerStatus::Paused;
        $timer->paused_at = $at;
        $timer->pause_reason = $reason;
        $timer->segment_started_at = null;
        $timer->due_at = null;
        $timer->warning_due_at = null;
    }

    private function markCompleted(TicketSlaTimer $timer, CarbonImmutable $at): void
    {
        $elapsed = $this->elapsed($timer, $at);

        $timer->elapsed_seconds = $elapsed;
        $timer->status = SlaTimerStatus::Completed;
        $timer->completed_at = $at;
        $timer->segment_started_at = null;
        $timer->paused_at = null;

        // Concluído fora do prazo antes da varredura registrar a violação
        if ($elapsed > $timer->target_seconds && $timer->breached_at === null) {
            $timer->breached_at = $timer->due_at ?? $at;
        }
    }

    private function warningSeconds(TicketSlaTimer $timer): int
    {
        return (int) floor($timer->target_seconds * $timer->warning_percent / 100);
    }

    /**
     * @param  list<SlaTimerStatus>  $statuses
     * @return Collection<int, TicketSlaTimer>
     */
    private function lockedTimers(Ticket $ticket, array $statuses, ?SlaTimerType $type = null): Collection
    {
        $query = TicketSlaTimer::query()
            ->with('schedule')
            ->where('ticket_id', $ticket->id)
            ->whereIn('status', array_map(fn (SlaTimerStatus $s) => $s->value, $statuses))
            ->when($type, fn ($q) => $q->where('type', $type->value));

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $result = $query->get();
        $ticket->unsetRelation('slaTimers');

        return $result;
    }
}
