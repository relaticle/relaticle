{{-- An edit made after the proposal, to a field Approve would write. Expects: $notice, $class. --}}
<p
    @class([
        'flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20',
        $class,
    ])
    role="status"
    data-proposal-overwrite-notice
>
    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
    <span>{{ $notice }}</span>
</p>
