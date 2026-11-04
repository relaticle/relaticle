@props(['view', 'active', 'badge' => null])

<button
    type="button"
    wire:click="setView('{{ $view->value }}')"
    wire:loading.attr="disabled"
    data-view="{{ $view->value }}"
    @if ($active) aria-current="page" @endif
    @class([
        'flex shrink-0 items-center gap-2 rounded-lg font-medium transition-colors focus:outline-none',
        'px-3 py-1.5 text-sm' => $view->isPrimary(),
        'px-2.5 py-1 text-xs' => ! $view->isPrimary(),
        'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-white' => $active,
        'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => ! $active,
    ])
>
    <x-filament::loading-indicator class="h-4 w-4 shrink-0" wire:loading wire:target="setView('{{ $view->value }}')" />
    {{ $view->getLabel() }}
    @if ($badge > 0)
        <span @class([
            'rounded px-1.5 py-0.5 text-[11px] font-semibold leading-none tabular-nums',
            'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-200' => $active,
            'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $active,
        ])>{{ $badge > 99 ? '99+' : $badge }}</span>
    @endif
</button>
