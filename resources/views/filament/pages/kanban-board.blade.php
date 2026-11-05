<x-filament-panels::page>
    @php
        $options = $this->filterOptions;
        $board = $this->board;
        $cards = $this->cards;
        $counts = $this->counts;
        $canMove = $this->canMove();
    @endphp

    {{-- ================================================= Filtros --}}
    <div class="hd-toolbar">
        <label>
            <span class="hd-label">Quadro</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="boardId">
                    @foreach ($options['boards'] as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label>
            <span class="hd-label">Busca</span>
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Número ou assunto" />
            </x-filament::input.wrapper>
        </label>

        <label>
            <span class="hd-label">Responsável</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="assignee">
                    <option value="">Todos</option>
                    <option value="me">★ Meus</option>
                    <option value="none">— Sem responsável</option>
                    @foreach ($options['agents'] as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label>
            <span class="hd-label">Equipe</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="teamId">
                    <option value="">Todas</option>
                    @foreach ($options['teams'] as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label>
            <span class="hd-label">Prioridade</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="priorityId">
                    <option value="">Todas</option>
                    @foreach ($options['priorities'] as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        @if (count($options['types']))
            <label>
                <span class="hd-label">Tipo</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="typeId">
                        <option value="">Todos</option>
                        @foreach ($options['types'] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif

        <label>
            <span class="hd-label">SLA</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="slaState">
                    <option value="">Todos</option>
                    <option value="at_risk">Em alerta</option>
                    <option value="breached">Violados</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <x-filament::button color="gray" icon="heroicon-m-x-mark" wire:click="resetFilters" size="sm">
            Limpar
        </x-filament::button>

        <div wire:loading.delay class="hd-syncing">
            sincronizando…
        </div>
    </div>

    {{-- ================================================= Quadro --}}
    @if (! $board)
        <x-filament::section>
            Nenhum quadro disponível para você. Peça a um gestor para criar um quadro ou incluí-lo na equipe certa.
        </x-filament::section>
    @else
        <div class="hd-board" wire:poll.{{ (int) config('helpdesk.kanban.poll_seconds', 20) }}s.visible="refreshBoard">
            @foreach ($board->columns as $column)
                @php
                    $columnCards = $cards->get($column->id, collect());
                    $total = $counts[$column->id] ?? 0;
                    $overWip = $column->wip_limit && $total >= $column->wip_limit;
                @endphp

                <section class="hd-column" style="--hd-col: {{ $column->color }}" wire:key="col-{{ $column->id }}-v{{ $this->version }}">
                    <header class="hd-column__head">
                        <span class="hd-column__title" title="{{ $column->name }}">{{ $column->name }}</span>
                        <span class="hd-column__meta">
                            @if ($column->sla_behavior === \App\Enums\SlaBehavior::Pause)
                                <span class="hd-chip" title="SLA pausado nesta coluna">⏸ SLA</span>
                            @endif
                            <span @class(['hd-chip', 'hd-chip--over' => $overWip]) title="Tickets na coluna{{ $column->wip_limit ? ' / limite WIP' : '' }}">
                                {{ $total }}{{ $column->wip_limit ? ' / '.$column->wip_limit : '' }}
                            </span>
                        </span>
                    </header>

                    <div
                        class="hd-column__body"
                        data-column-id="{{ $column->id }}"
                        x-data
                        x-init="
                            if (! window.Sortable) { console.warn('Sortable indisponível'); return; }
                            window.Sortable.create($el, {
                                group: 'hd-board-{{ $board->id }}',
                                draggable: '[data-ticket-id]',
                                animation: 150,
                                ghostClass: 'hd-ghost',
                                dragClass: 'hd-drag',
                                disabled: {{ $canMove ? 'false' : 'true' }},
                                delayOnTouchOnly: true,
                                delay: 120,
                                onStart: () => document.querySelectorAll('.hd-column__body').forEach(c => c.classList.add('hd-column--drop')),
                                onEnd: (e) => {
                                    document.querySelectorAll('.hd-column__body').forEach(c => c.classList.remove('hd-column--drop'));
                                    if (e.from === e.to && e.oldDraggableIndex === e.newDraggableIndex) return;
                                    $wire.moveTicket(
                                        parseInt(e.item.dataset.ticketId),
                                        parseInt(e.to.dataset.columnId),
                                        e.newDraggableIndex ?? 0,
                                    );
                                },
                            });
                        "
                    >
                        @forelse ($columnCards as $ticket)
                            @php $sla = $this->slaFor($ticket); @endphp
                            <a
                                href="{{ \App\Filament\Resources\TicketResource::getUrl('view', ['record' => $ticket]) }}"
                                data-ticket-id="{{ $ticket->id }}"
                                wire:key="card-{{ $ticket->id }}"
                                @class(['hd-card', 'hd-card--breached' => $sla?->state === \App\Enums\SlaState::Breached])
                                style="--hd-prio: {{ $ticket->priority?->color ?? 'transparent' }}"
                            >
                                <div class="hd-card__top">
                                    <span class="hd-card__number">{{ $ticket->number }}</span>
                                    <span class="hd-chip" title="Prioridade">
                                        <span class="hd-dot" style="background: {{ $ticket->priority?->color }}"></span>
                                        {{ $ticket->priority?->name }}
                                    </span>
                                </div>

                                <div class="hd-card__subject">{{ $ticket->subject }}</div>

                                @if ($ticket->company || $ticket->contact)
                                    <div class="hd-card__sub">
                                        {{ collect([$ticket->company?->name, $ticket->contact?->name])->filter()->implode(' · ') }}
                                    </div>
                                @endif

                                <div class="hd-card__foot">
                                    <span style="display:inline-flex;gap:.3rem;align-items:center;flex-wrap:wrap">
                                        <x-hd.sla-badge :snapshot="$sla" :live="true" />
                                        @if ($ticket->type)
                                            <span class="hd-chip">
                                                @if ($ticket->type->color)
                                                    <span class="hd-dot" style="background: {{ $ticket->type->color }}"></span>
                                                @endif
                                                {{ $ticket->type->name }}
                                            </span>
                                        @endif
                                        @if ($ticket->internal_notes_count)
                                            <span class="hd-chip" title="Notas internas">🔒 {{ $ticket->internal_notes_count }}</span>
                                        @endif
                                    </span>

                                    @if ($ticket->assignee)
                                        <span class="hd-avatar" title="{{ $ticket->assignee->name }}">
                                            {{ \Illuminate\Support\Str::of($ticket->assignee->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                                        </span>
                                    @else
                                        <span class="hd-avatar hd-avatar--empty" title="Sem responsável">?</span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="hd-column__empty">Solte um card aqui</div>
                        @endforelse

                        @if ($total > $columnCards->count() && $columnCards->count() >= (int) config('helpdesk.kanban.cards_per_column', 100))
                            <div class="hd-column__empty">+{{ $total - $columnCards->count() }} não exibidos — refine os filtros</div>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
