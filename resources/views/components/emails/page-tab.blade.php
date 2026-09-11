@props(['tab', 'active', 'badge' => null])

<button
    type="button"
    wire:click="setTab('{{ $tab->value }}')"
    wire:loading.attr="disabled"
    @class([
        'flex shrink-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none',
        'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $active,
        'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => ! $active,
    ])
>
    <x-filament::icon :icon="$tab->getIcon()" class="h-4 w-4 shrink-0" wire:loading.remove wire:target="setTab('{{ $tab->value }}')" />
    <x-filament::loading-indicator class="h-4 w-4 shrink-0" wire:loading wire:target="setTab('{{ $tab->value }}')" />
    {{ $tab->getLabel() }}
    @if ($badge !== null)
        <span @class([
            'rounded px-1.5 py-0.5 text-[11px] font-semibold leading-none tabular-nums',
            'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-200' => $active,
            'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $active,
        ])>{{ $badge > 99 ? '99+' : $badge }}</span>
    @endif
</button>
