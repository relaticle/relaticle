@php
    $state = $getState();
@endphp

@include('email-integration::filament.infolists.partials.meeting-attendee-row', ['state' => $state, 'compact' => false])
