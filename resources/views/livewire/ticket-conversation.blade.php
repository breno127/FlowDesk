<div class="hd-chat">
    {{-- ================================================= Composer (sempre visível, no topo) --}}
    @if ($this->canWrite())
        <form
            wire:submit="send"
            x-on:keydown.ctrl.enter.prevent="$wire.send()"
            x-on:keydown.meta.enter.prevent="$wire.send()"
            @class([
                'hd-composer',
                'hd-composer--internal' => ($data['visibility'] ?? null) === \App\Enums\MessageVisibility::Internal->value,
            ])
        >
            {{ $this->form }}

            <div class="hd-composer__footer">
                <span class="hd-composer__hint">Ctrl + Enter para enviar</span>

                <x-filament::button
                    type="submit"
                    icon="heroicon-m-paper-airplane"
                    :color="($data['visibility'] ?? null) === \App\Enums\MessageVisibility::Internal->value ? 'warning' : 'primary'"
                    wire:target="send"
                >
                    {{ ($data['visibility'] ?? null) === \App\Enums\MessageVisibility::Internal->value ? 'Registrar nota' : 'Enviar resposta' }}
                </x-filament::button>
            </div>
        </form>
    @endif

    {{-- ================================================= Mensagens: mais recente no topo --}}
    <div class="hd-chat__list" wire:poll.30s.visible>
        @forelse ($this->messages as $message)
            @php
                $side = match (true) {
                    $message->direction === \App\Enums\MessageDirection::System => 'system',
                    $message->direction === \App\Enums\MessageDirection::Inbound => 'in',
                    default => 'out',
                };
            @endphp

            <article
                wire:key="msg-{{ $message->id }}"
                @class([
                    'hd-bubble',
                    "hd-bubble--{$side}",
                    'hd-bubble--internal' => $message->isInternal(),
                    'hd-bubble--mine' => $this->isMine($message),
                ])
            >
                <header class="hd-bubble__head">
                    <span class="hd-bubble__author">{{ $message->authorName() }}</span>
                    <span class="hd-bubble__meta">
                        {{ config('helpdesk.channels.'.$message->channel, $message->channel) }}
                        @if ($message->isInternal())
                            · <span class="hd-msg__tag">🔒 Nota interna</span>
                        @endif
                        @if ($message->delivery_status)
                            · {{ $message->delivery_status }}
                        @endif
                        ·
                        <time datetime="{{ $message->created_at->toIso8601String() }}" title="{{ $message->created_at->format('d/m/Y H:i:s') }}">
                            {{ $message->created_at->diffForHumans() }}
                        </time>
                    </span>
                </header>

                @if (filled(strip_tags($message->body, '<img>')))
                    <div class="hd-bubble__body">{{ $message->bodyHtml() }}</div>
                @endif

                @if ($message->attachments->isNotEmpty())
                    <ul class="hd-files">
                        @foreach ($message->attachments as $file)
                            <li wire:key="file-{{ $file->id }}">
                                @if ($file->isPreviewableImage())
                                    <a href="{{ $file->url() }}" target="_blank" rel="noopener" class="hd-files__thumb">
                                        <img src="{{ $file->url() }}" alt="{{ $file->original_name }}" loading="lazy">
                                    </a>
                                @else
                                    <a href="{{ $file->url(download: true) }}" class="hd-files__chip">
                                        <x-filament::icon icon="heroicon-m-paper-clip" class="h-4 w-4" />
                                        <span class="hd-files__name">{{ $file->original_name }}</span>
                                        <span class="hd-files__size">{{ $file->humanSize() }}</span>
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @empty
            <p class="hd-empty">Nenhuma mensagem ainda. Escreva a primeira resposta acima.</p>
        @endforelse

        @if ($this->total > $this->messages->count())
            <div class="hd-chat__more">
                <x-filament::button color="gray" size="sm" wire:click="loadOlder" icon="heroicon-m-chevron-down">
                    Carregar mensagens anteriores ({{ $this->total - $this->messages->count() }})
                </x-filament::button>
            </div>
        @endif
    </div>
</div>
