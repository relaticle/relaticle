{{-- Inline sync state for the sync_email row while a mailbox import is in flight. --}}
<span
    class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400"
    aria-hidden="true"
>
    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 motion-safe:animate-spin" />
</span>

<span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">
    {{ __('filament/pages/dashboard.activation.steps.sync_email.syncing') }}
</span>

@if ($syncPercent !== null)
    <span
        class="flex-shrink-0 text-xs tabular-nums text-gray-400 dark:text-gray-500"
        aria-label="{{ __('filament/pages/dashboard.activation.steps.sync_email.syncing_percent', ['percent' => $syncPercent]) }}"
    >
        {{ $syncPercent }}%
    </span>
@endif
