@php
    $groupLabel = __('favorites.group');
@endphp

<li
    x-data="{
        label: @js($groupLabel),
        isStale: false,
        neighbour(item, direction) {
            let sibling = item[direction]
            while (sibling && ! sibling.dataset.favoriteKey) {
                sibling = sibling[direction]
            }
            return sibling?.dataset.favoriteKey ?? null
        },
        move(key) {
            const item = this.$root.querySelector(`[data-favorite-key='${key}']`)
            this.$wire.move(key, item.parentElement.dataset.favoriteFolder ?? null, this.neighbour(item, 'previousElementSibling'), this.neighbour(item, 'nextElementSibling'))
        },
    }"
    {{-- Refreshing re-mounts this component and its modal, so Create & create another defers it until the modal closes. --}}
    x-on:favorites-folder-created.window="isStale = true"
    x-on:modal-closed="if (isStale) { isStale = false; $wire.dispatch('refresh-sidebar'); $wire.dispatch('favorites-updated') }"
    data-group-label="{{ $groupLabel }}"
    x-bind:class="{ 'fi-collapsed': $store.sidebar.groupIsCollapsed(label) }"
    {{-- -ml-2 offsets this hook, a sibling of Filament's .fi-sidebar-nav-groups list (margin-left: -8px). --}}
    @class(['fi-sidebar-group fi-sidebar-favorites fi-collapsible -ml-2', 'hidden' => ! $hasFavorites])
>
    @if ($hasFavorites)
    <div
        x-on:click="$store.sidebar.toggleCollapsedGroup(label)"
        x-show="$store.sidebar.isOpen"
        x-transition:enter="fi-transition-enter"
        x-transition:enter-start="fi-transition-enter-start"
        x-transition:enter-end="fi-transition-enter-end"
        x-transition:leave="fi-transition-leave"
        x-transition:leave-start="fi-transition-leave-start"
        x-transition:leave-end="fi-transition-leave-end"
        class="fi-sidebar-group-btn"
    >
        <span class="fi-sidebar-group-label">{{ $groupLabel }}</span>

        <x-filament::icon-button
            color="gray"
            icon="lucide-folder-plus"
            size="xs"
            :label="__('favorites.actions.new_folder')"
            x-on:click.stop
            wire:click="mountAction('createFolder')"
            class="fi-sidebar-favorites-new-folder-btn"
        />

        <x-filament::icon-button
            color="gray"
            icon="lucide-chevron-up"
            :label="$groupLabel"
            x-bind:aria-expanded="! $store.sidebar.groupIsCollapsed(label)"
            x-on:click.stop="$store.sidebar.toggleCollapsedGroup(label)"
            class="fi-sidebar-group-collapse-btn"
        />
    </div>

    <ul
        x-show="$store.sidebar.isOpen ? ! $store.sidebar.groupIsCollapsed(label) : true"
        x-collapse.duration.200ms
        x-ref="favorites"
        x-sort.ghost="move"
        x-sort:group="favorites"
        x-sort:config="{ delay: 200, delayOnTouchOnly: true, animation: $dragAnimation() }"
        class="fi-sidebar-group-items"
    >
        @foreach ($items as $item)
            @if ($item->isFolder())
                @include('livewire.app.favorites-sidebar-nav.folder', ['folder' => $item])
            @else
                @include('livewire.app.favorites-sidebar-nav.favorite', ['favorite' => $item])
            @endif
        @endforeach
    </ul>
    @endif

    <x-filament-actions::modals />
</li>
