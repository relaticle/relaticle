@php
    $groupLabel = __('favorites.group');
@endphp

<li
    x-data="{
        label: @js($groupLabel),
        keys(list) {
            return [...list.children].filter((item) => item.dataset.favoriteKey).map((item) => item.dataset.favoriteKey)
        },
        arrange() {
            const folders = {}
            this.$refs.favorites.querySelectorAll('[data-favorite-folder]').forEach((list) => {
                folders[list.dataset.favoriteFolder] = this.keys(list).map((key) => key.replace(/^record:/, ''))
            })
            this.$wire.arrange({ top: this.keys(this.$refs.favorites), folders })
        },
    }"
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
        x-sort.ghost="arrange"
        x-sort:group="favorites"
        x-sort:config="{ delay: 200, delayOnTouchOnly: true, animation: $dragAnimation() }"
        class="fi-sidebar-group-items"
    >
        @foreach ($items as $item)
            @if ($item instanceof \App\Models\FavoriteFolder)
                @include('livewire.app.favorites-sidebar-nav.folder', ['folder' => $item])
            @else
                @include('livewire.app.favorites-sidebar-nav.favorite', ['favorite' => $item])
            @endif
        @endforeach
    </ul>
    @endif

    <x-filament-actions::modals />
</li>
