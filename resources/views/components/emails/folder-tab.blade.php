@props(['folder', 'active', 'icon', 'label', 'badge' => null])

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
    <x-filament::loading-indicator class="h-4 w-4" wire:loading wire:target="setFolder('{{ $folder }}')" />
    {{ $label }}
    @if ($badge !== null && $badge > 0)
        <span class="inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] rounded-full bg-primary-500 px-1 text-[10px] font-semibold leading-none text-white">
            {{ $badge > 99 ? '99+' : $badge }}
        </span>
    @endif
</button>
