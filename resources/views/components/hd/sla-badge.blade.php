@props([
    /** @var \App\Services\Sla\SlaSnapshot|null $snapshot */
    'snapshot' => null,
    'live' => false,
    'showType' => true,
])

@php
    $s = $snapshot;
    $countdown = $s && $live && $s->ticking && in_array($s->state->value, ['ok', 'warning', 'critical', 'breached'], true);
    $short = $s?->type === \App\Enums\SlaTimerType::FirstResponse ? '1ªR' : 'SOL';
    $title = $s ? sprintf('%s · %s · %s%% consumido%s', $s->type->getLabel(), $s->state->getLabel(), number_format($s->percent, 0),
        $s->dueAt ? ' · vence '.$s->dueAt->timezone(config('app.timezone'))->format('d/m H:i') : '') : '';
@endphp

@if ($s)
    <span
        @if ($countdown)
            x-data="{
                r: {{ (int) $s->remainingSeconds }},
                target: {{ max(1, (int) $s->targetSeconds) }},
                w: {{ (int) $s->warningPercent }},
                c: {{ (int) $s->criticalPercent }},
                timer: null,
                init() { this.timer = setInterval(() => this.r--, 1000) },
                destroy() { clearInterval(this.timer) },
                get pct() { return (this.target - this.r) / this.target * 100 },
                get state() { return this.r <= 0 ? 'breached' : (this.pct >= this.c ? 'critical' : (this.pct >= this.w ? 'warning' : 'ok')) },
                get label() {
                    const n = Math.abs(this.r), d = Math.floor(n / 86400), h = Math.floor(n % 86400 / 3600), m = Math.floor(n % 3600 / 60), s = n % 60;
                    const p = (v) => String(v).padStart(2, '0');
                    const t = d > 0 ? `${d}d ${p(h)}h` : (h > 0 ? `${h}h ${p(m)}m` : `${m}m ${p(s)}s`);
                    return (this.r < 0 ? '-' : '') + t;
                },
            }"
            x-bind:class="'hd-sla--' + state"
        @endif
        {{ $attributes->class(['hd-sla', 'hd-sla--'.$s->state->value]) }}
        title="{{ $title }}"
    >
        @if ($showType)
            <span>{{ $short }}</span>
        @endif

        @if ($s->state === \App\Enums\SlaState::Paused)
            <span>⏸ {{ $s->humanRemaining() }}</span>
        @elseif ($s->state === \App\Enums\SlaState::Met)
            <span>✓ OK</span>
        @else
            <span @if ($countdown) x-text="label" @endif>{{ $s->humanRemaining() }}</span>
        @endif
    </span>
@endif
