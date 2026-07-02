@props(['search'])

<div class="shrink-0 border-b border-gray-200 dark:border-gray-700 p-3">
    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
            <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400 dark:text-gray-500" wire:loading.remove wire:target="search" />
            <x-filament::loading-indicator class="h-4 w-4 text-primary-500" wire:loading wire:target="search" />
        </div>
        <input
            wire:model.live.debounce.300ms="search"
            type="text"
            placeholder="{{ __('filament/pages/email-inbox.search.placeholder') }}"
            class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 py-2 pl-9 pr-3 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-500 dark:focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
        />
        @if (filled($search))
            <button
                wire:click="$set('search', '')"
                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
            >
                <x-heroicon-o-x-mark class="h-4 w-4" />
            </button>
        @endif
    </div>
</div>
