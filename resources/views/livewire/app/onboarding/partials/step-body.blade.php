{{-- The inside of one checklist row. Shared by the two row shapes: a link for a
     step with a destination, and one that seeds the chat composer. --}}
<span @class([
    'flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg border transition',
    'border-primary-200 bg-primary-50 text-primary-600 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-400' => $step->complete,
    'border-gray-200 text-gray-500 group-hover:border-gray-300 dark:border-white/10 dark:text-gray-400' => ! $step->complete,
])>
    @if($step->complete)
        <x-heroicon-s-check class="h-3.5 w-3.5" />
    @else
        {{ svg($step->icon, 'h-3.5 w-3.5') }}
    @endif
</span>

<span class="min-w-0 flex-1">
    <span @class([
        'block truncate text-sm',
        'text-gray-400 line-through dark:text-gray-500' => $step->complete,
        'font-medium text-gray-900 dark:text-white' => ! $step->complete,
    ])>
        {{ $step->label }}
    </span>
</span>
