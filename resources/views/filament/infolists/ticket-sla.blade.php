@php
    /** @var \App\Models\Ticket $ticket */
    $ticket = $getRecord();
    $ticket->load(['slaTimers' => fn ($q) => $q->with('schedule')]);
    $snapshots = app(\App\Services\Sla\SlaCalculationService::class)->snapshotsFor($ticket);
@endphp

<div style="display:flex;flex-direction:column;gap:.75rem">
    @forelse ($snapshots as $snapshot)
        @php $timer = $ticket->slaTimers->firstWhere('type', $snapshot->type); @endphp
        <div class="hd-panel">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                <span class="hd-panel__title">{{ $snapshot->type->getLabel() }}</span>
                <x-hd.sla-badge :snapshot="$snapshot" :live="true" :show-type="false" />
            </div>
            <div class="hd-panel__meta">
                Meta {{ intdiv($snapshot->targetSeconds, 60) }} min ·
                {{ number_format(min($snapshot->percent, 999), 0) }}% consumido
                @if ($snapshot->dueAt && $snapshot->state !== \App\Enums\SlaState::Met)
                    · vence {{ $snapshot->dueAt->timezone(config('app.timezone'))->format('d/m H:i') }}
                @endif
                · {{ $timer?->schedule?->name ?? '24x7' }}
            </div>
            @if ($timer?->pause_reason && $snapshot->state === \App\Enums\SlaState::Paused)
                <div class="hd-panel__meta">Pausado: {{ $timer->pause_reason }}</div>
            @endif
            <div class="hd-progress hd-progress--{{ $snapshot->state->value }}">
                <span style="width: {{ min(100, max(0, $snapshot->percent)) }}%"></span>
            </div>
        </div>
    @empty
        <p class="hd-empty">Nenhuma meta de SLA aplicável a este ticket.</p>
    @endforelse
</div>
