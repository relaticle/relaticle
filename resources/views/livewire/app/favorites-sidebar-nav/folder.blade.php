@php
    $folderKey = 'favorites-folder:'.$folder->getKey();
    $isClosed = '$store.sidebar.groupIsCollapsed('.\Illuminate\Support\Js::from($folderKey).')';
@endphp

<li
    wire:key="favorite-folder-{{ $folder->getKey() }}"
    x-sort:item="@js($folder->getKey())"
    data-favorite-key="{{ $folder->getKey() }}"
    data-favorite-kind="folder"
    class="fi-sidebar-favorites-folder"
>
    <div
        x-show="$store.sidebar.isOpen"
        class="fi-sidebar-item group/favorite-folder relative"
    >
        <button
            type="button"
            x-on:click="$store.sidebar.toggleCollapsedGroup(@js($folderKey))"
            x-bind:aria-expanded="! {{ $isClosed }}"
            class="fi-sidebar-item-btn w-full pe-8 text-start group-hover/favorite-folder:bg-(--surface-sidebar-hover-bg)"
        >
            <x-lucide-folder-open :x-show="'! '.$isClosed" class="fi-icon fi-size-lg fi-sidebar-item-icon" />
            <x-lucide-folder :x-show="$isClosed" x-cloak class="fi-icon fi-size-lg fi-sidebar-item-icon" />
            <span title="{{ $folder->name }}" class="fi-sidebar-item-label truncate">{{ $folder->name }}</span>
        </button>

        <x-filament::dropdown placement="bottom-end" teleport class="absolute inset-y-0 end-1 flex items-center">
            <x-slot name="trigger">
                <x-filament::icon-button
                    color="gray"
                    size="sm"
                    icon="lucide-ellipsis"
                    :label="__('favorites.folders.menu')"
                />
            </x-slot>

            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item
                    icon="lucide-pencil"
                    wire:click="mountAction('renameFolder', { folder: '{{ $folder->getKey() }}' })"
                >
                    {{ __('favorites.folders.rename') }}
                </x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item
                    icon="lucide-trash-2"
                    color="danger"
                    wire:click="mountAction('deleteFolder', { folder: '{{ $folder->getKey() }}' })"
                >
                    {{ __('favorites.folders.delete') }}
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>

    <ul
        x-show="! $store.sidebar.isOpen || ! {{ $isClosed }}"
        x-collapse.duration.200ms
        x-sort.ghost="move"
        {{-- A folder may not hold a folder, so this list only accepts favorites. --}}
        x-sort:config="{ group: { name: 'favorites', put: (to, from, item) => item.dataset.favoriteKind === 'record' }, delay: 200, delayOnTouchOnly: true, animation: $dragAnimation() }"
        data-favorite-folder="{{ $folder->getKey() }}"
        x-bind:class="{ 'ps-5': $store.sidebar.isOpen }"
        class="fi-sidebar-favorites-folder-items"
    >
        @forelse ($folder->children as $favorite)
            @include('livewire.app.favorites-sidebar-nav.favorite', ['favorite' => $favorite])
        @empty
            <li x-sort:ignore x-show="$store.sidebar.isOpen" data-favorite-folder-empty class="px-3 py-1.5 text-xs text-gray-500 dark:text-gray-400">
                {{ __('favorites.folders.empty') }}
            </li>
        @endforelse
    </ul>
</li>
