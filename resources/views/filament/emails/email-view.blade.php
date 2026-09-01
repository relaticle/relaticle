@php
    $email = $record;
    $email->loadMissing(['from', 'body', 'attachments', 'labels', 'participants']);
@endphp

<x-emails.email-view :record="$email" />
