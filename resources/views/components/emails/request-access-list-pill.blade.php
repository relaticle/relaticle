@props(['email', 'ownerName', 'requested' => false])

@if ($requested)
    <span
        class="mt-1 inline-flex max-w-full items-center gap-1 rounded-md border border-gray-200/80 bg-gray-50 px-2 py-0.5 text-[11px] font-medium leading-none text-gray-500 dark:border-gray-700/80 dark:bg-gray-900/60 dark:text-gray-400"
    >
        <x-heroicon-m-clock class="h-3 w-3 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
        <span class="truncate">{{ __('filament/pages/email-inbox.list_row.requested') }}</span>
    </span>
@else
    <button
        type="button"
        wire:click="mountAction('requestAccess', { emailId: '{{ $email->id }}' })"
        class="mt-1 inline-flex max-w-full items-center gap-1 rounded-md border border-gray-200 bg-white px-2 py-0.5 text-[11px] font-medium leading-none text-gray-600 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
    >
        <x-heroicon-m-lock-closed class="h-3 w-3 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
        <span class="truncate">{{ __('filament/pages/email-inbox.list_row.request_access', ['name' => $ownerName]) }}</span>
    </button>
@endif
