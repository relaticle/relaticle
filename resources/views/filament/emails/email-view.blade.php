@php
    $email = $record;
    $pendingAccessRequests = $pendingAccessRequests ?? collect();
    $inlineAccessActions = $inlineAccessActions ?? false;
    $email->loadMissing(['from', 'body', 'attachments', 'labels', 'participants']);
@endphp

<div class="fi-email-reader-modal-content flex min-h-0 flex-1 flex-col">
    <x-emails.reader-access-bar
        :pending-access-requests="$pendingAccessRequests"
        :inline-access-actions="$inlineAccessActions"
    />

    <x-emails.email-view :record="$email" />
</div>
