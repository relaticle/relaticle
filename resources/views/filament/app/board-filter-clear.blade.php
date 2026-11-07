<button
    type="button"
    class="fi-board-filter-clear"
    aria-label="{{ __('filament/pages/boards.filters_button.clear') }}"
    x-tooltip="{ content: @js(__('filament/pages/boards.filters_button.clear')), theme: $store.theme }"
    wire:click="removeTableFilters"
    wire:loading.attr="disabled"
    wire:target="removeTableFilters"
>
    <x-filament::icon icon="lucide-x" class="fi-board-filter-clear-icon" />
</button>
