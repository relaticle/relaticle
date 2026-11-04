{{-- The phone tab bar: the sidebar's first four items, then More, which opens the sidebar for the rest. --}}
@php
    $items = collect(filament()->getNavigation())
        ->flatMap(fn (\Filament\Navigation\NavigationGroup $group): iterable => $group->getItems())
        ->take(4);
@endphp

<nav aria-label="{{ __('filament/panel.bottom_navigation.label') }}" class="fi-bottom-nav">
    @foreach ($items as $item)
        @php($isActive = $item->isActive())

        <a
            {{ \Filament\Support\generate_href_html($item->getUrl(), $item->shouldOpenUrlInNewTab()) }}
            @if ($isActive) aria-current="page" @endif
            @class(['fi-bottom-nav-item', 'fi-active' => $isActive])
        >
            <x-filament::icon :icon="($isActive ? $item->getActiveIcon() : null) ?? $item->getIcon()" />
            <span>{{ $item->getLabel() }}</span>
        </a>
    @endforeach

    <button
        type="button"
        aria-controls="fi-main-sidebar"
        x-data="{}"
        x-bind:aria-expanded="$store.sidebar.isOpen"
        x-on:click="$store.sidebar.open($event.currentTarget)"
        @class(['fi-bottom-nav-item', 'fi-active' => $items->doesntContain(fn (\Filament\Navigation\NavigationItem $item): bool => $item->isActive())])
    >
        <x-filament::icon icon="heroicon-o-bars-3" />
        <span>{{ __('filament/panel.bottom_navigation.more') }}</span>
    </button>
</nav>
