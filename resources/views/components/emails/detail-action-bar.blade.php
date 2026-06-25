@props(['email'])

@php
    $authUser         = auth()->user();
    $isOwner          = $email->user_id === $authUser->getKey();
    $canSummarize     = $isOwner || $authUser->can('viewBody', $email);
    $canRequestAccess = $authUser->cannot('viewBody', $email) && $authUser->can('requestAccess', $email);
@endphp

@if ($isOwner || $canSummarize || $canRequestAccess)
    <div class="flex shrink-0 items-center justify-end gap-1 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 px-4 py-2">

        @if ($isOwner)
            {{ ($this->manageSharingAction)(['emailId' => $email->id]) }}
        @endif

        @if ($canSummarize)
            {{ ($this->summarizeThreadAction)(['emailId' => $email->id]) }}
        @endif

        @if ($canRequestAccess)
            {{ ($this->requestAccessAction)(['emailId' => $email->id]) }}
        @endif

    </div>
@endif
