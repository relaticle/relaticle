{{-- `grow` splits the tabs evenly across a narrow column (the record pages); without
     it they size to their labels, which is what the full-width inbox toolbar wants. --}}
@props(['folder', 'active', 'icon', 'label', 'badge' => null, 'grow' => true])

<button
    wire:click="setFolder('{{ $folder }}')"
    wire:loading.attr="disabled"
    wire:loading.class="opacity-60 cursor-not-allowed"
    @class([
        'flex items-center justify-center gap-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
        'flex-1 py-3' => $grow,
        'h-8 shrink-0 rounded-md px-2.5' => ! $grow,
        'border-b-2 border-primary-500 text-primary-600 dark:text-primary-400' => $active && $grow,
        'border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' => ! $active && $grow,
        'bg-white text-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:text-white dark:ring-white/10' => $active && ! $grow,
        'text-gray-500 hover:bg-white/70 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' => ! $active && ! $grow,
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
