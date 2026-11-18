@php
    $entity = \App\Enums\CrmEntity::tryFromModel($favorite->record);
    $name = $favorite->record->{$entity->titleColumn()};
    $url = app(\App\Support\CanonicalRecordUrl::class)->build($entity, $favorite->record_id, $workspace);
@endphp

<li
    wire:key="favorite-record-{{ $favorite->getKey() }}"
    x-sort:item="@js('record:'.$favorite->getKey())"
    data-favorite-key="record:{{ $favorite->getKey() }}"
    @class([
        'fi-sidebar-item group/favorite-item relative',
        'fi-active' => $url !== null && request()->url() === $url,
    ])
>
    <a
        href="{{ $url }}"
        wire:navigate
        x-on:click="$store.sidebar.isMobile && $store.sidebar.close()"
        x-bind:aria-label="$store.sidebar.isOpen ? null : @js($name)"
        x-data="filamentSidebarTooltip"
        x-tooltip="getTooltip({ content: @js($name) })"
        class="fi-sidebar-item-btn pe-8 group-hover/favorite-item:bg-(--surface-sidebar-hover-bg)"
    >
        {{ \Filament\Support\generate_icon_html($entity->icon(), attributes: new \Filament\Support\View\ComponentAttributeBag(['class' => 'fi-sidebar-item-icon']), size: \Filament\Support\Enums\IconSize::Large) }}
        <span x-show="$store.sidebar.isOpen" title="{{ $name }}" class="fi-sidebar-item-label flex min-w-0 items-baseline gap-2">
            <span class="truncate">{{ $name }}</span>
            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ __('favorites.entities.'.$entity->value) }}</span>
        </span>
    </a>

    <button
        type="button"
        x-show="$store.sidebar.isOpen"
        wire:click="removeFavorite({{ \Illuminate\Support\Js::from($favorite->getKey()) }})"
        aria-label="{{ __('favorites.actions.remove') }}"
        title="{{ __('favorites.actions.remove') }}"
        class="absolute inset-y-0 end-1 my-auto flex size-6 items-center justify-center rounded-md text-gray-500 opacity-0 transition hover:bg-gray-200/70 hover:text-gray-700 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 group-hover/favorite-item:opacity-100 pointer-coarse:opacity-100 max-sm:size-11 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200"
    >
        <x-lucide-star-off class="size-4" />
    </button>
</li>
