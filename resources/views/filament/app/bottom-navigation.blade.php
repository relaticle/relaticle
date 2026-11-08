{{-- The phone tab bar: four fixed destinations, then More, which opens the sidebar for the rest. --}}
@php
    $items = collect([
        \App\Filament\Pages\Dashboard::class,
        \App\Filament\Resources\PeopleResource::class,
        \App\Filament\Resources\CompanyResource::class,
        \App\Filament\Resources\OpportunityResource::class,
    ])
        ->filter(fn (string $destination): bool => $destination::canAccess())
        ->flatMap(fn (string $destination): array => $destination::getNavigationItems());

    $tenantRoot = rtrim((string) filament()->getUrl(filament()->getTenant()), '/');

    $activeItems = $items->filter(function (\Filament\Navigation\NavigationItem $item) use ($tenantRoot): bool {
        $url = rtrim((string) $item->getUrl(), '/');

        return $item->isActive() || ($url !== $tenantRoot && str_starts_with(url()->current(), $url.'/'));
    });
@endphp

<nav aria-label="{{ __('filament/panel.bottom_navigation.label') }}" class="fi-bottom-nav">
    @foreach ($items as $item)
        @php($isActive = $activeItems->containsStrict($item))

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
        @class(['fi-bottom-nav-item', 'fi-active' => $activeItems->isEmpty()])
    >
        <x-filament::icon icon="lucide-menu" />
        <span>{{ __('filament/panel.bottom_navigation.more') }}</span>
    </button>
</nav>
