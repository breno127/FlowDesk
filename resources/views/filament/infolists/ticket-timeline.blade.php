@php
    /** @var \App\Models\Ticket $ticket */
    $ticket = $getRecord();
    $canSeeInternal = auth()->user()->can('tickets.internal_note') || auth()->user()->can('tickets.view_all');
    $messages = $ticket->messages()
        ->with('author')
        ->when(! $canSeeInternal, fn ($q) => $q->where('visibility', \App\Enums\MessageVisibility::Public->value))
        ->get();

    $highlight = function (string $body): string {
        $escaped = e(strip_tags($body));

        return preg_replace('/(?<![\w.@])@([A-Za-z0-9][A-Za-z0-9._-]{1,63})/u', '<span class="hd-mention">@$1</span>', $escaped);
    };
@endphp

<div class="hd-timeline" wire:poll.30s.visible>
    @forelse ($messages as $message)
        @php
            $classes = ['hd-msg'];
            if ($message->isInternal()) $classes[] = 'hd-msg--internal';
            if ($message->direction === \App\Enums\MessageDirection::Inbound) $classes[] = 'hd-msg--inbound';
            if ($message->direction === \App\Enums\MessageDirection::System) $classes[] = 'hd-msg--system';
        @endphp
        <article class="{{ implode(' ', $classes) }}" wire:key="msg-{{ $message->id }}">
            <header class="hd-msg__head">
                <span>
                    <span class="hd-msg__author">{{ $message->authorName() }}</span>
                    · {{ config('helpdesk.channels.'.$message->channel, $message->channel) }}
                    @if ($message->isInternal())
                        · <span class="hd-msg__tag">Nota interna</span>
                    @endif
                    @if ($message->delivery_status)
                        · {{ $message->delivery_status }}
                    @endif
                </span>
                <time datetime="{{ $message->created_at->toIso8601String() }}" title="{{ $message->created_at->format('d/m/Y H:i:s') }}">
                    {{ $message->created_at->diffForHumans() }}
                </time>
            </header>
            <div class="hd-msg__body">{!! $highlight($message->body) !!}</div>
        </article>
    @empty
        <p class="hd-empty">Nenhuma mensagem ainda. Use “Responder” ou “Nota interna”.</p>
    @endforelse
</div>
