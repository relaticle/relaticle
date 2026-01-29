{{-- Shared action buttons for value row partials --}}
@props([
    'selectedColumn',
    'rawValue',
    'hasCorrection' => false,
    'showSkip' => true,
    'showUndo' => null,
    'undoTitle' => 'Undo correction',
])

@php
    $showUndo = $showUndo ?? $hasCorrection;
@endphp

<div class="flex items-center bg-gray-50 dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 shrink-0">
    @if ($showUndo)
        <button
            wire:click.stop.preserve-scroll="undoCorrection({{ Js::from($rawValue) }}, {{ Js::from($rawValue) }})"
            class="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-300 dark:hover:bg-gray-700 transition-colors"
            title="{{ $undoTitle }}"
        >
            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="w-4 h-4"/>
        </button>
        @if ($showSkip)
            <div class="w-px h-4 bg-gray-200 dark:bg-gray-700"></div>
        @endif
    @endif

    @if ($showSkip)
        <button
            wire:click.stop.preserve-scroll="skipValue({{ Js::from($rawValue) }})"
            class="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-50 dark:hover:text-gray-400 dark:hover:bg-gray-950/50 transition-colors"
            title="Skip this value"
        >
            <x-filament::icon icon="heroicon-o-no-symbol" class="w-4 h-4"/>
        </button>
    @endif

    {{ $slot }}
</div>
