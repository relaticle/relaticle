@component('mail::message')
@if($inviterName)
{{ __('teams.mail.invitation.line_with_inviter', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
@else
{{ __('teams.mail.invitation.line', ['team' => $teamName, 'role' => $roleName]) }}
@endif

@component('mail::button', ['url' => $acceptUrl])
{{ __('teams.mail.invitation.action') }}
@endcomponent

@if($invitation->expires_at)
{{ __('teams.mail.invitation.expiry', ['expiry' => $invitation->expires_at->diffForHumans()]) }}
@endif

{{ __('teams.mail.invitation.ignore') }}
@endcomponent
