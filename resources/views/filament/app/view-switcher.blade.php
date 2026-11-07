@php
    $icons = [
        'list' => 'lucide-list',
        'board' => 'lucide-columns-3',
        'cards' => 'lucide-layout-grid',
    ];

    $active = collect($views)->search(fn (array $view): bool => $view['active']);
@endphp

<x-filament::dropdown placement="bottom-start" class="fi-view-switcher">
    <x-slot name="trigger">
        <x-filament::button
            color="gray"
            size="sm"
            :icon="$icons[$active]"
            :aria-label="__('filament/pages/boards.view_switcher.label')"
        >
            {{ __("filament/pages/boards.view_switcher.{$active}") }}

            <x-filament::icon icon="lucide-chevron-down" class="fi-view-switcher-chevron" />
        </x-filament::button>
    </x-slot>

    <x-filament::dropdown.list>
        @foreach ($views as $key => $view)
            <x-filament::dropdown.list.item
                tag="a"
                :href="$view['url']"
                :icon="$icons[$key]"
                :color="$view['active'] ? 'primary' : 'gray'"
                :spa-mode="true"
                :aria-current="$view['active'] ? 'page' : null"
            >
                {{ __("filament/pages/boards.view_switcher.{$key}") }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
