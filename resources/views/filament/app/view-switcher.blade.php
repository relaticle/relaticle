@php
    $segmentClasses = 'flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600';
    $activeClasses = 'bg-gray-100 text-primary-600 dark:bg-white/10 dark:text-primary-400';
    $inactiveClasses = 'text-gray-500 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white';
@endphp

<nav
    class="fi-view-switcher inline-flex items-center gap-0.5"
    aria-label="{{ __('filament/pages/boards.view_switcher.label') }}"
>
    <a
        href="{{ $listUrl }}"
        wire:navigate
        @class([$segmentClasses, $activeClasses => $active === 'list', $inactiveClasses => $active !== 'list'])
        @if ($active === 'list') aria-current="page" @endif
        aria-label="{{ __('filament/pages/boards.view_switcher.list') }}"
    >
        <x-filament::icon icon="heroicon-o-list-bullet" class="h-4 w-4" />
        <span class="fi-view-switcher-label">{{ __('filament/pages/boards.view_switcher.list') }}</span>
    </a>

    <a
        href="{{ $boardUrl }}"
        wire:navigate
        @class([$segmentClasses, $activeClasses => $active === 'board', $inactiveClasses => $active !== 'board'])
        @if ($active === 'board') aria-current="page" @endif
        aria-label="{{ __('filament/pages/boards.view_switcher.board') }}"
    >
        <x-filament::icon icon="heroicon-o-view-columns" class="h-4 w-4" />
        <span class="fi-view-switcher-label">{{ __('filament/pages/boards.view_switcher.board') }}</span>
    </a>
</nav>
