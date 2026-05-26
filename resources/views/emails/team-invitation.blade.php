@component('mail::message')
{{ __('You\'ve been invited to join the :team team on Relaticle.', ['team' => $invitation->team->name]) }}

@component('mail::button', ['url' => $acceptUrl])
{{ __('Accept Invitation') }}
@endcomponent

@if($invitation->expires_at)
{{ __('This invitation expires :expiry.', ['expiry' => $invitation->expires_at->diffForHumans()]) }}
@endif

{{ __('If you weren\'t expecting this, you can safely ignore this email.') }}
@endcomponent
