@props(['search'])

<div class="shrink-0 border-b border-gray-200 dark:border-gray-700 p-3">
    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
            <x-heroicon-o-magnifying-glass class="h-4 w-4 text-gray-400 dark:text-gray-500" wire:loading.remove wire:target="search" />
            <svg wire:loading wire:target="search" class="h-4 w-4 animate-spin text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
        </div>
        <input
            wire:model.live.debounce.300ms="search"
            type="text"
            placeholder="Search emails…"
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
