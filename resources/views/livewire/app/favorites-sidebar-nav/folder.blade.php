@php
    $folderKey = 'favorites-folder:'.$folder->getKey();
    $isClosed = '$store.sidebar.groupIsCollapsed('.\Illuminate\Support\Js::from($folderKey).')';
@endphp

<li
    wire:key="favorite-folder-{{ $folder->getKey() }}"
    class="fi-sidebar-favorites-folder"
>
    <div
        x-show="$store.sidebar.isOpen"
        class="fi-sidebar-item group/favorite-folder relative"
    >
        <button
            type="button"
            x-on:click="$store.sidebar.toggleCollapsedGroup(@js($folderKey))"
            x-bind:aria-expanded="! $store.sidebar.groupIsCollapsed(@js($folderKey))"
            class="fi-sidebar-item-btn w-full pe-8 text-start group-hover/favorite-folder:bg-(--surface-sidebar-hover-bg)"
        >
            <x-lucide-folder-open :x-show="'! '.$isClosed" class="fi-icon fi-size-lg fi-sidebar-item-icon" />
            <x-lucide-folder :x-show="$isClosed" x-cloak class="fi-icon fi-size-lg fi-sidebar-item-icon" />
            <span title="{{ $folder->name }}" class="fi-sidebar-item-label truncate">{{ $folder->name }}</span>
        </button>

        <x-filament::dropdown placement="bottom-end" teleport class="absolute end-1 top-1/2 -translate-y-1/2">
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
                    wire:click="mountAction('renameFolder', { folder: @js($folder->getKey()) })"
                >
                    {{ __('favorites.folders.rename') }}
                </x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item
                    icon="lucide-trash-2"
                    color="danger"
                    wire:click="mountAction('deleteFolder', { folder: @js($folder->getKey()) })"
                >
                    {{ __('favorites.folders.delete') }}
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>

    <ul
        x-show="! $store.sidebar.isOpen || ! $store.sidebar.groupIsCollapsed(@js($folderKey))"
        x-collapse.duration.200ms
        x-bind:class="{ 'ps-5': $store.sidebar.isOpen }"
        class="fi-sidebar-favorites-folder-items"
    >
        @foreach ($folder->favoriteRecords as $favorite)
            @include('livewire.app.favorites-sidebar-nav.favorite', ['favorite' => $favorite])
        @endforeach
    </ul>
</li>
