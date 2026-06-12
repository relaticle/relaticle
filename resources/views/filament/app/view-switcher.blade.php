@php
    $segmentClasses = 'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600';
    $activeClasses = 'bg-white text-primary-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-primary-400 dark:ring-white/10';
    $inactiveClasses = 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200';
@endphp

<nav
    class="fi-view-switcher inline-flex items-center gap-0.5 rounded-lg bg-gray-100 p-0.5 font-normal dark:bg-white/5"
    aria-label="{{ __('filament/pages/boards.view_switcher.label') }}"
>
    <a
        href="{{ $listUrl }}"
        wire:navigate
        @class([$segmentClasses, $activeClasses => $active === 'list', $inactiveClasses => $active !== 'list'])
        @if ($active === 'list') aria-current="page" @endif
    >
        <x-filament::icon icon="heroicon-m-list-bullet" class="h-4 w-4" />
        {{ __('filament/pages/boards.view_switcher.list') }}
    </a>

    <a
        href="{{ $boardUrl }}"
        wire:navigate
        @class([$segmentClasses, $activeClasses => $active === 'board', $inactiveClasses => $active !== 'board'])
        @if ($active === 'board') aria-current="page" @endif
    >
        <x-filament::icon icon="heroicon-m-view-columns" class="h-4 w-4" />
        {{ __('filament/pages/boards.view_switcher.board') }}
    </a>
</nav>
