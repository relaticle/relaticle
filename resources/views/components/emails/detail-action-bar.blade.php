@props(['email'])

@php
    $authUser         = auth()->user();
    $isOwner          = $email->user_id === $authUser->getKey();
    $canSummarize     = filled($email->thread_id) && $authUser->can('viewSubject', $email);
    $canRequestAccess = $authUser->cannot('viewBody', $email) && $authUser->can('requestAccess', $email);
@endphp

@if ($isOwner || $canSummarize || $canRequestAccess)
    <div class="flex shrink-0 items-center justify-end gap-1 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 px-4 py-2">

        @if ($isOwner)
            <button
                x-on:click="$wire.mountAction('manageSharing', { emailId: '{{ $email->id }}' })"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
            >
                <x-heroicon-o-lock-open class="h-3.5 w-3.5" />
                Sharing
            </button>
        @endif

        @if ($canSummarize)
            <button
                x-on:click="$wire.mountAction('summarizeThread', { emailId: '{{ $email->id }}' })"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
            >
                <x-heroicon-o-sparkles class="h-3.5 w-3.5" />
                Summarize Thread
            </button>
        @endif

        @if ($canRequestAccess)
            <button
                x-on:click="$wire.mountAction('requestAccess', { emailId: '{{ $email->id }}' })"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
            >
                <x-heroicon-o-key class="h-3.5 w-3.5" />
                Request Access
            </button>
        @endif

    </div>
@endif
