@props(['folder', 'active', 'icon', 'label'])

<button
    wire:click="setFolder('{{ $folder }}')"
    wire:loading.attr="disabled"
    wire:loading.class="opacity-60 cursor-not-allowed"
    @class([
        'flex flex-1 items-center justify-center gap-1.5 py-3 text-sm font-medium transition-colors focus:outline-none',
        'border-b-2 border-primary-500 text-primary-600 dark:text-primary-400' => $active,
        'border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => ! $active,
    ])
>
    <x-dynamic-component :component="$icon" class="h-4 w-4" wire:loading.remove wire:target="setFolder('{{ $folder }}')" />
    <svg wire:loading wire:target="setFolder('{{ $folder }}')" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
    </svg>
    {{ $label }}
</button>
