{{-- Skipped value row: Bordered container with skip icon and restore action --}}
<div class="flex-1 flex items-center rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
    <div class="flex-1 flex items-center gap-2 px-2 py-1">
        <x-filament::icon icon="heroicon-o-no-symbol" class="w-4 h-4 text-gray-400 shrink-0"/>
        <span class="text-sm text-gray-400 dark:text-gray-500 italic">Skipped</span>
    </div>
    <x-import-wizard-new::value-row-actions
        :selected-column="$selectedColumn"
        :raw-value="$rawValue"
        :show-undo="true"
        :show-skip="false"
        undo-title="Restore original value"
    >
        <div class="w-px h-4 bg-gray-200 dark:bg-gray-700"></div>
        <button
            wire:click.preserve-scroll="updateMappedValue({{ Js::from($selectedColumn) }}, {{ Js::from($rawValue) }}, {{ Js::from($rawValue) }})"
            class="p-1.5 text-gray-400 hover:text-primary-600 hover:bg-primary-50 dark:hover:text-primary-400 dark:hover:bg-primary-950/50 transition-colors"
            title="Add a value"
        >
            <x-filament::icon icon="heroicon-o-plus" class="w-4 h-4"/>
        </button>
    </x-import-wizard-new::value-row-actions>
</div>
