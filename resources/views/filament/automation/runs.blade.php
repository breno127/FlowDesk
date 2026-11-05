<div style="display:flex;flex-direction:column;gap:.5rem">
    @forelse ($runs as $run)
        <div class="hd-run">
            <div class="hd-run__head">
                <span>
                    <span class="hd-run__status--{{ $run->status }}">{{ $run->status === 'success' ? 'Sucesso' : 'Falha' }}</span>
                    · {{ config('helpdesk.automation.events.'.$run->event, $run->event) }}
                    @if ($run->ticket)
                        · {{ $run->ticket->number }}
                    @endif
                </span>
                <span>{{ $run->created_at->format('d/m H:i:s') }}</span>
            </div>
            @foreach ($run->executed_actions ?? [] as $action)
                <div style="margin-top:.2rem">→ {{ $action['result'] ?? $action['type'] }}</div>
            @endforeach
            @if ($run->error)
                <div class="hd-run__error">{{ $run->error }}</div>
            @endif
        </div>
    @empty
        <p class="hd-empty">Esta regra ainda não foi executada.</p>
    @endforelse
</div>
