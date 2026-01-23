{{-- Skipped value row: Bordered container with skip icon and restore action --}}
<div class="flex-1 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
    <div class="flex-1 flex items-center gap-2 px-2 py-1">
        <x-filament::icon icon="heroicon-o-no-symbol" class="w-4 h-4 text-gray-400 shrink-0"/>
        <span class="text-sm text-gray-400 dark:text-gray-500 italic">Skipped</span>
    </div>
    <div class="flex items-center bg-gray-50 dark:bg-gray-900 border-l border-gray-200 dark:border-gray-700 shrink-0">
        <button
            wire:click.stop.preserve-scroll="unskipValue({{ Js::from($rawValue) }})"
            class="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-300 dark:hover:bg-gray-700 transition-colors"
            title="Restore original value"
        >
            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="w-4 h-4"/>
        </button>
    </div>
</div>
