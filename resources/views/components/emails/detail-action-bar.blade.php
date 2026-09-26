@props(['email'])

{{-- Rendered inline inside the email header action cluster, with no chrome of its own. --}}
@php
    $authUser         = auth()->user();
    $isOwner          = $email->user_id === $authUser->getKey();
    $canSummarize     = $isOwner || $authUser->can('viewBody', $email);
    $canRequestAccess = $authUser->cannot('viewBody', $email)
        && $authUser->can('requestAccess', $email)
        && ! $email->hasPendingAccessRequestFrom($authUser);
@endphp

@if ($isOwner)
    {{ ($this->manageSharingAction)(['emailId' => $email->id]) }}
@endif

@if ($canSummarize)
    {{ ($this->summarizeThreadAction)(['emailId' => $email->id]) }}
@endif

@if ($canRequestAccess)
    {{ ($this->requestAccessAction)(['emailId' => $email->id]) }}
@endif
