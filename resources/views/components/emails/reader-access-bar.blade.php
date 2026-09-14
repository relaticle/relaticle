@props([
    'pendingAccessRequests',
])

@if ($pendingAccessRequests->isNotEmpty())
    <div class="shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-900/20">
        <div class="mb-2 flex items-center gap-2">
            <x-heroicon-o-key class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span class="text-xs font-semibold text-amber-800 dark:text-amber-300">
                {{ trans_choice('filament/pages/email-inbox.pending_access.heading', $pendingAccessRequests->count(), ['count' => $pendingAccessRequests->count()]) }}
            </span>
        </div>
        <div class="space-y-1.5">
            @foreach ($pendingAccessRequests as $accessRequest)
                <div class="flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-white px-3 py-2 dark:border-amber-800 dark:bg-gray-900">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate text-xs font-medium text-gray-900 dark:text-gray-100">
                            {{ $accessRequest->requester?->name ?? __('filament/pages/email-inbox.pending_access.unknown_user') }}
                        </span>
                        <span class="inline-flex shrink-0 items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">
                            {{ \Relaticle\EmailIntegration\Enums\EmailPrivacyTier::from($accessRequest->tier_requested)->getLabel() }}
                        </span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button
                            wire:click="mountAction('approveAccessRequest', { requestId: '{{ $accessRequest->id }}' })"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md bg-success-600 px-2.5 py-1 text-xs font-medium text-white transition-colors hover:bg-success-700"
                        >
                            <x-heroicon-o-check class="h-3 w-3" />
                            {{ __('filament/pages/email-inbox.pending_access.approve') }}
                        </button>
                        <button
                            wire:click="mountAction('denyAccessRequest', { requestId: '{{ $accessRequest->id }}' })"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            <x-heroicon-o-x-mark class="h-3 w-3" />
                            {{ __('filament/pages/email-inbox.pending_access.deny') }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
