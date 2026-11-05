<x-filament-widgets::widget>
    <div class="hd-shortcuts">
        @foreach ($this->getShortcuts() as $shortcut)
            @php($enabled = filled($shortcut['url']))

            <a
                @if ($enabled) href="{{ $shortcut['url'] }}" @else aria-disabled="true" @endif
                @class(['hd-shortcut', 'hd-shortcut--disabled' => ! $enabled])
            >
                <span class="hd-shortcut__icon">
                    <x-filament::icon :icon="$shortcut['icon']" class="h-6 w-6" />
                </span>

                <span class="hd-shortcut__text">
                    <span class="hd-shortcut__label">
                        {{ $shortcut['label'] }}
                        @unless ($enabled)
                            <x-filament::badge color="gray" size="sm">Em breve</x-filament::badge>
                        @endunless
                    </span>
                    <span class="hd-shortcut__desc">{{ $shortcut['description'] }}</span>
                </span>

                @if ($enabled)
                    <x-filament::icon icon="heroicon-m-arrow-right" class="hd-shortcut__arrow h-5 w-5" />
                @endif
            </a>
        @endforeach
    </div>
</x-filament-widgets::widget>
